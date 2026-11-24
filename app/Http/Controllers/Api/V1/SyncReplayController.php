<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncDeadLetterQueue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncReplayController extends Controller
{
    protected SyncController $syncController;

    public function __construct(SyncController $syncController)
    {
        $this->syncController = $syncController;
    }

    /**
     * Replay a diagnostic outbox bundle safely into MySQL.
     * Failed or invalid mutations are moved to Dead-Letter Queue (DLQ).
     */
    public function replayBundle(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->input('tenant_id') ?? $user->tenant_id ?? 'TENANT-DEFAULT';
        $businessId = $request->input('business_id') ?? $user->business_id;

        $outboxRows = $request->input('outbox_rows', []);
        $writes = $request->input('writes', []);

        if (empty($outboxRows) && empty($writes)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No outbox_rows or writes provided for replay.',
            ], 422);
        }

        $receiptId = 'REPLAY-' . strtoupper(Str::random(12));
        $succeeded = 0;
        $failedToDlq = 0;
        $results = [];

        // Normalize outboxRows to writes if provided
        $normalizedWrites = [];
        if (!empty($outboxRows)) {
            foreach ($outboxRows as $row) {
                $collection = $row['entityType'] ?? $row['collection'] ?? null;
                $docId = $row['documentId'] ?? $row['id'] ?? null;
                $operation = strtoupper((string)($row['operation'] ?? 'UPDATE'));
                $batchId = $row['batchId'] ?? null;

                $rawPayload = $row['payloadJson'] ?? $row['payload'] ?? [];
                $fields = is_string($rawPayload) ? (json_decode($rawPayload, true) ?: []) : $rawPayload;

                if (!$collection || !$docId) {
                    continue;
                }

                if ($operation === 'DELETE') {
                    $normalizedWrites[] = [
                        'delete' => "$collection/$docId",
                        '_meta' => ['batchId' => $batchId, 'collection' => $collection, 'docId' => $docId, 'payload' => $fields],
                    ];
                } else {
                    $normalizedWrites[] = [
                        'update' => [
                            'name' => "$collection/$docId",
                            'fields' => $fields,
                        ],
                        '_meta' => ['batchId' => $batchId, 'collection' => $collection, 'docId' => $docId, 'payload' => $fields],
                    ];
                }
            }
        } else {
            foreach ($writes as $w) {
                $normalizedWrites[] = $w;
            }
        }

        // Process writes with DLQ containment
        foreach ($normalizedWrites as $write) {
            $meta = $write['_meta'] ?? [];
            unset($write['_meta']);

            DB::beginTransaction();
            try {
                $subRequest = Request::create('/api/v1/sync/commit', 'POST', [
                    'writes' => [$write],
                ]);
                $response = $this->syncController->commit($subRequest);

                if ($response->getStatusCode() === 200) {
                    DB::commit();
                    $succeeded++;
                    $results[] = [
                        'target' => $meta['docId'] ?? 'unknown',
                        'status' => 'COMMITTED',
                    ];
                } else {
                    throw new \Exception('Sync commit returned status ' . $response->getStatusCode());
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $failedToDlq++;

                $collection = $meta['collection'] ?? 'unknown';
                $docId = $meta['docId'] ?? (string)Str::uuid();
                $batchId = $meta['batchId'] ?? null;

                SyncDeadLetterQueue::create([
                    'tenant_id' => $tenantId,
                    'business_id' => $businessId,
                    'collection' => $collection,
                    'document_id' => $docId,
                    'batch_id' => $batchId,
                    'error_code' => 'REPLAY_VALIDATION_ERROR',
                    'error_message' => $e->getMessage(),
                    'payload' => $meta['payload'] ?? $write,
                    'status' => 'FAILED',
                ]);

                $results[] = [
                    'target' => $docId,
                    'status' => 'QUEUED_IN_DLQ',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'receipt_id' => $receiptId,
                'total' => count($normalizedWrites),
                'succeeded' => $succeeded,
                'queued_to_dlq' => $failedToDlq,
                'processed_at' => Carbon::now()->toIso8601String(),
                'results' => $results,
            ],
        ]);
    }

    /**
     * List items stored in Dead-Letter Queue.
     */
    public function listDlq(Request $request): JsonResponse
    {
        $status = $request->query('status', 'FAILED');
        $query = SyncDeadLetterQueue::query();

        if ($status !== 'ALL') {
            $query->where('status', $status);
        }

        $items = $query->orderBy('id', 'desc')->paginate(25);

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }

    /**
     * Reprocess a single DLQ item after inspection or manual modification.
     */
    public function reprocessDlq(Request $request, int $id): JsonResponse
    {
        $dlq = SyncDeadLetterQueue::findOrFail($id);
        $user = $request->user();

        $payload = $request->input('payload', $dlq->payload);
        $collection = $dlq->collection;
        $docId = $dlq->document_id;

        DB::beginTransaction();
        try {
            $write = [
                'update' => [
                    'name' => "$collection/$docId",
                    'fields' => $payload,
                ],
            ];

            $subRequest = Request::create('/api/v1/sync/commit', 'POST', [
                'writes' => [$write],
            ]);
            $response = $this->syncController->commit($subRequest);

            if ($response->getStatusCode() === 200) {
                $dlq->status = 'REPROCESSED';
                $dlq->resolved_by = $user ? $user->id : null;
                $dlq->resolved_at = Carbon::now();
                $dlq->save();

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'DLQ item reprocessed successfully into MySQL.',
                ]);
            } else {
                throw new \Exception('Re-commit failed with status ' . $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reprocess DLQ item: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Discard an invalid DLQ item.
     */
    public function discardDlq(Request $request, int $id): JsonResponse
    {
        $dlq = SyncDeadLetterQueue::findOrFail($id);
        $user = $request->user();

        $dlq->status = 'DISCARDED';
        $dlq->resolved_by = $user ? $user->id : null;
        $dlq->resolved_at = Carbon::now();
        $dlq->save();

        return response()->json([
            'status' => 'success',
            'message' => 'DLQ item discarded.',
        ]);
    }
}
