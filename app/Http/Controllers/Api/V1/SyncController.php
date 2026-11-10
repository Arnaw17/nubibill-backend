<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    /**
     * Decode Firestore typed field values into native PHP values.
     */
    public static function decodeValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_key_exists('stringValue', $value)) {
            return (string) $value['stringValue'];
        }
        if (array_key_exists('booleanValue', $value)) {
            return (bool) $value['booleanValue'];
        }
        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }
        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }
        if (array_key_exists('timestampValue', $value)) {
            return $value['timestampValue'];
        }
        if (array_key_exists('nullValue', $value)) {
            return null;
        }
        if (isset($value['arrayValue']['values']) && is_array($value['arrayValue']['values'])) {
            return array_map([self::class, 'decodeValue'], $value['arrayValue']['values']);
        }
        if (isset($value['mapValue']['fields']) && is_array($value['mapValue']['fields'])) {
            return self::decodeFields($value['mapValue']['fields']);
        }
        return $value;
    }

    public static function decodeFields(array $fields): array
    {
        $decoded = [];
        foreach ($fields as $k => $v) {
            $decoded[$k] = self::decodeValue($v);
        }
        return $decoded;
    }

    /**
     * Encode native PHP values into Firestore typed representation.
     */
    public static function encodeValue($value)
    {
        if ($value === null) {
            return ['nullValue' => null];
        }
        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }
        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }
        if ($value instanceof \DateTimeInterface) {
            return ['timestampValue' => $value->format('Y-m-d\TH:i:s.u\Z')];
        }
        if (is_array($value)) {
            // Check if associative
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return ['mapValue' => ['fields' => self::encodeFields($value)]];
            }
            return [
                'arrayValue' => [
                    'values' => array_map([self::class, 'encodeValue'], $value),
                ],
            ];
        }
        return ['stringValue' => (string) $value];
    }

    public static function encodeFields(array $fields): array
    {
        $encoded = [];
        foreach ($fields as $k => $v) {
            $encoded[$k] = self::encodeValue($v);
        }
        return $encoded;
    }

    /**
     * Format/normalize fields array to strict Firestore REST wire format.
     */
    public static function formatFirestoreFields(array $fields): array
    {
        $formatted = [];
        foreach ($fields as $k => $v) {
            if (is_array($v) && (
                array_key_exists('stringValue', $v) ||
                array_key_exists('booleanValue', $v) ||
                array_key_exists('integerValue', $v) ||
                array_key_exists('doubleValue', $v) ||
                array_key_exists('timestampValue', $v) ||
                array_key_exists('nullValue', $v) ||
                array_key_exists('mapValue', $v) ||
                array_key_exists('arrayValue', $v)
            )) {
                $formatted[$k] = $v;
            } elseif (is_array($v) && isset($v['__type__']) && $v['__type__'] === 'timestamp') {
                $formatted[$k] = ['timestampValue' => Carbon::parse($v['value'])->toIso8601String()];
            } else {
                $formatted[$k] = self::encodeValue($v);
            }
        }
        return $formatted;
    }

    /**
     * Commit atomic writes from SQLite SyncEngine into MySQL.
     */
    public function commit(Request $request): JsonResponse
    {
        $writes = $request->input('writes', []);
        $now = now();
        $isoNow = $now->toIso8601String();
        $writeResults = [];

        DB::beginTransaction();
        try {
            foreach ($writes as $write) {
                if (isset($write['delete'])) {
                    $target = (string) $write['delete'];
                    $parsed = $this->parsePath($target);
                    $collection = $parsed['collection'];
                    $id = $parsed['id'];

                    if ($collection && $id && Schema::hasTable($collection)) {
                        $deleteUpdate = [
                            'updated_at' => $now,
                        ];
                        if (Schema::hasColumn($collection, 'is_deleted')) {
                            $deleteUpdate['is_deleted'] = 1;
                        }
                        if (Schema::hasColumn($collection, 'status')) {
                            $deleteUpdate['status'] = 'DELETED';
                        }
                        DB::table($collection)->where('id', $id)->update($deleteUpdate);
                    }
                    $writeResults[] = ['updateTime' => $isoNow];
                } elseif (isset($write['update'])) {
                    $doc = $write['update'];
                    $target = (string) ($doc['name'] ?? '');
                    $parsed = $this->parsePath($target);
                    $collection = $parsed['collection'];
                    $id = $parsed['id'];
                    $rawFields = $doc['fields'] ?? [];
                    $decoded = self::decodeFields($rawFields);

                    if ($collection && $id && Schema::hasTable($collection)) {
                        $businessId = $decoded['businessId'] ?? null;
                        $outletId = $decoded['outletId'] ?? null;
                        $tenantId = $decoded['tenantId'] ?? null;
                        $status = strtoupper((string) ($decoded['status'] ?? 'ACTIVE'));
                        $isDeleted = (!empty($decoded['isDeleted']) || $status === 'DELETED') ? 1 : 0;
                        $docPath = "$collection/$id";

                        $existing = DB::table($collection)->where('id', $id)->first();
                        $existingPayload = $existing ? (json_decode($existing->payload, true) ?: []) : [];
                        $existingDecoded = self::decodeFields($existingPayload['fields'] ?? []);
                        $mergedFields = array_merge($existingDecoded, $decoded);

                        $payload = [
                            '_documentId' => $id,
                            '_documentPath' => $docPath,
                            'fields' => $mergedFields,
                        ];

                        $row = $this->mapRowColumns($collection, $id, $docPath, $decoded, $payload, $existing, $now);

                        if ($existing) {
                            DB::table($collection)->where('id', $id)->update($row);
                        } else {
                            $row['created_at'] = isset($decoded['createdAt']) ? Carbon::parse($decoded['createdAt']) : $now;
                            DB::table($collection)->insert($row);
                        }
                    }
                    $writeResults[] = ['updateTime' => $isoNow];
                }
            }

            DB::commit();

            return response()->json([
                'commitTime' => $isoNow,
                'writeResults' => $writeResults,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => [
                    'code' => 500,
                    'message' => 'Sync commit to MySQL failed: ' . $e->getMessage(),
                    'status' => 'INTERNAL',
                ],
            ], 500);
        }
    }

    /**
     * Map Firestore document fields to MySQL table columns dynamically.
     */
    private function mapRowColumns(string $table, string $id, string $docPath, array $decoded, array $payload, ?object $existing, Carbon $now): array
    {
        $status = strtoupper((string) ($decoded['status'] ?? 'ACTIVE'));
        $isDeleted = (!empty($decoded['isDeleted']) || $status === 'DELETED') ? 1 : 0;

        $row = [
            'id' => $id,
            'document_path' => $docPath,
            'payload' => json_encode($payload),
            'updated_at' => $now,
        ];

        // Specific mapping for stock_movements
        if ($table === 'stock_movements') {
            $row['product_id'] = $decoded['productId'] ?? $decoded['product_id'] ?? ($existing->product_id ?? '');
            $row['variant_id'] = $decoded['variantId'] ?? $decoded['variant_id'] ?? ($existing->variant_id ?? null);
            $row['type'] = strtolower((string) ($decoded['type'] ?? $decoded['transactionType'] ?? ($existing->type ?? 'adjustment')));

            $qtyChange = $decoded['changedQuantity'] ?? $decoded['qtyChange'] ?? $decoded['qtyBase'] ?? null;
            if ($qtyChange !== null && is_numeric($qtyChange)) {
                $row['qty_change'] = abs($qtyChange) >= 1000 ? ($qtyChange / 1000.0) : (float) $qtyChange;
            } elseif ($existing && isset($existing->qty_change)) {
                $row['qty_change'] = $existing->qty_change;
            } else {
                $row['qty_change'] = 0.0;
            }

            $balAfter = $decoded['balanceAfter'] ?? $decoded['newQuantity'] ?? null;
            if ($balAfter !== null && is_numeric($balAfter)) {
                $row['balance_after'] = abs($balAfter) >= 1000 ? ($balAfter / 1000.0) : (float) $balAfter;
            } elseif ($existing && isset($existing->balance_after)) {
                $row['balance_after'] = $existing->balance_after;
            } else {
                $row['balance_after'] = null;
            }

            $row['reference_type'] = strtolower((string) ($decoded['referenceType'] ?? $decoded['transactionType'] ?? ($existing->reference_type ?? 'adjustment')));
            $row['reference_id'] = $decoded['referenceId'] ?? $decoded['sourceDocumentId'] ?? ($existing->reference_id ?? $id);
            $row['condition'] = $decoded['condition'] ?? ($existing->condition ?? 'resalable');
            $row['note'] = $decoded['note'] ?? $decoded['reason'] ?? ($existing->note ?? null);
            $row['created_by'] = $decoded['performedBy'] ?? $decoded['userId'] ?? $decoded['approvedBy'] ?? ($existing->created_by ?? null);
            $row['entry_source'] = $decoded['entrySource'] ?? ($existing->entry_source ?? 'new');
            $row['is_historical'] = !empty($decoded['isHistorical']) ? 1 : ($existing->is_historical ?? 0);
        }

        // Standard column checks across any table
        if (Schema::hasColumn($table, 'business_id')) {
            $row['business_id'] = $decoded['businessId'] ?? ($existing->business_id ?? null);
        }
        if (Schema::hasColumn($table, 'outlet_id')) {
            $row['outlet_id'] = $decoded['outletId'] ?? ($existing->outlet_id ?? null);
        }
        if (Schema::hasColumn($table, 'tenant_id')) {
            $row['tenant_id'] = $decoded['tenantId'] ?? ($existing->tenant_id ?? null);
        }
        if (Schema::hasColumn($table, 'status')) {
            $row['status'] = $status;
        }
        if (Schema::hasColumn($table, 'is_deleted')) {
            $row['is_deleted'] = $isDeleted;
        }
        if (Schema::hasColumn($table, 'product_id') && !isset($row['product_id'])) {
            $row['product_id'] = $decoded['productId'] ?? ($existing->product_id ?? null);
        }
        if (Schema::hasColumn($table, 'variant_id') && !isset($row['variant_id'])) {
            $row['variant_id'] = $decoded['variantId'] ?? ($existing->variant_id ?? null);
        }

        return $row;
    }

    /**
     * Resolve Firestore collection name to actual MySQL table name.
     */
    private function resolveTable(string $collection): ?string
    {
        if (Schema::hasTable($collection)) {
            return $collection;
        }
        if ($collection === 'user' && Schema::hasTable('users')) {
            return 'users';
        }
        return null;
    }

    /**
     * Synthesize Firestore-compatible profile fields from a MySQL user record.
     */
    private function synthesizeUserFields(object $row): array
    {
        $role = strtoupper((string) ($row->role ?? 'USER'));
        $accountType = ($role === 'EMPLOYEE') ? 'employee' : 'owner';
        $status = strtoupper((string) ($row->status ?? 'ACTIVE'));
        $isActive = ($status !== 'INACTIVE') && empty($row->is_deleted);

        return [
            'uid' => ['stringValue' => (string) $row->id],
            'name' => ['stringValue' => (string) ($row->name ?? 'User')],
            'email' => ['stringValue' => (string) ($row->email ?? '')],
            'phone' => ['stringValue' => (string) ($row->phone ?? '')],
            'accountType' => ['stringValue' => $accountType],
            'isActive' => ['booleanValue' => $isActive],
            'status' => ['stringValue' => $status],
            'role' => ['stringValue' => $role],
        ];
    }

    /**
     * Fetch a single document by collection and ID from MySQL in Firestore wire format.
     */
    public function getDocument(string $collection, string $id): JsonResponse
    {
        $id = urldecode($id);
        $table = $this->resolveTable($collection);

        if (!$table) {
            return response()->json([
                'error' => [
                    'code' => 404,
                    'message' => "Collection $collection not found.",
                    'status' => 'NOT_FOUND',
                ],
            ], 404);
        }

        $row = DB::table($table)->where('id', $id)->first();
        if (!$row && Schema::hasColumn($table, 'email')) {
            $row = DB::table($table)->where('email', $id)->first();
        }

        if (!$row) {
            return response()->json([
                'error' => [
                    'code' => 404,
                    'message' => "Document $id not found in $collection.",
                    'status' => 'NOT_FOUND',
                ],
            ], 404);
        }

        $payload = json_decode($row->payload ?? '', true) ?: [];
        $rawFields = $payload['fields'] ?? [];

        if (empty($rawFields)) {
            if ($table === 'users') {
                $rawFields = $this->synthesizeUserFields($row);
            } else {
                $rawFields = self::encodeFields((array) $row);
            }
        } else {
            $rawFields = self::formatFirestoreFields($rawFields);
        }

        $createTime = $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : now()->toIso8601String();
        $updateTime = $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : now()->toIso8601String();

        return response()->json([
            'name' => "projects/nubibilling-local/databases/(default)/documents/{$collection}/{$row->id}",
            'fields' => $rawFields,
            'createTime' => $createTime,
            'updateTime' => $updateTime,
        ]);
    }

    /**
     * Create or upsert a single document into MySQL in Firestore wire format.
     */
    public function createDocument(Request $request, string $collection, ?string $id = null): JsonResponse
    {
        $id = $id ?: ($request->query('documentId') ?: $request->input('id'));
        $rawFields = $request->input('fields', []);
        $decoded = self::decodeFields($rawFields);
        $table = $this->resolveTable($collection);

        if (!$table && $collection === 'system_error_analytics') {
            try {
                if (!Schema::hasTable('system_error_analytics')) {
                    Schema::create('system_error_analytics', function ($tableBlueprint) {
                        $tableBlueprint->string('id', 255)->primary();
                        $tableBlueprint->string('document_path', 500)->nullable();
                        $tableBlueprint->string('business_id', 255)->nullable()->index();
                        $tableBlueprint->string('outlet_id', 255)->nullable()->index();
                        $tableBlueprint->string('tenant_id', 255)->nullable()->index();
                        $tableBlueprint->string('status', 100)->default('ACTIVE')->index();
                        $tableBlueprint->tinyInteger('is_deleted')->default(0)->index();
                        $tableBlueprint->json('payload')->nullable();
                        $tableBlueprint->timestamps();
                    });
                }
                $table = 'system_error_analytics';
            } catch (\Throwable $e) {
                // Return success representation so error analytics never disrupts client flow
                $id = $id ?: ($decoded['id'] ?? (string) Str::uuid());
                $isoNow = now()->toIso8601String();
                return response()->json([
                    'name' => "projects/nubibilling-local/databases/(default)/documents/{$collection}/{$id}",
                    'fields' => self::formatFirestoreFields($rawFields),
                    'createTime' => $isoNow,
                    'updateTime' => $isoNow,
                ], 201);
            }
        }

        if (!$table) {
            return response()->json([
                'error' => [
                    'code' => 404,
                    'message' => "Collection $collection not found.",
                    'status' => 'NOT_FOUND',
                ],
            ], 404);
        }

        $id = $id ?: ($decoded['id'] ?? (string) Str::uuid());
        $now = now();
        $isoNow = $now->toIso8601String();
        $docPath = "$collection/$id";

        $payload = [
            '_documentId' => $id,
            '_documentPath' => $docPath,
            'fields' => $rawFields,
        ];

        $existing = DB::table($table)->where('id', $id)->first();
        $row = $this->mapRowColumns($table, $id, $docPath, $decoded, $payload, $existing, $now);

        if ($existing) {
            DB::table($table)->where('id', $id)->update($row);
        } else {
            $row['created_at'] = isset($decoded['createdAt']) ? Carbon::parse($decoded['createdAt']) : $now;
            DB::table($table)->insert($row);
        }

        return response()->json([
            'name' => "projects/nubibilling-local/databases/(default)/documents/{$collection}/{$id}",
            'fields' => self::formatFirestoreFields($rawFields),
            'createTime' => isset($row['created_at']) && $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d\TH:i:s.u\Z')
                : $isoNow,
            'updateTime' => $isoNow,
        ], 201);
    }

    /**
     * Run a structured query against MySQL tables in Firestore format.
     */
    public function runQuery(Request $request): JsonResponse
    {
        $structured = $request->input('structuredQuery', []);
        $from = $structured['from'][0]['collectionId'] ?? null;
        $requestedReadTime = $request->input('readTime');
        $now = is_string($requestedReadTime) && $requestedReadTime !== ''
            ? $requestedReadTime
            : now()->toIso8601String();

        $table = $from ? $this->resolveTable($from) : null;
        if (!$table) {
            return response()->json([
                ['readTime' => $now],
            ]);
        }

        $query = DB::table($table);
        if (Schema::hasColumn($table, 'is_deleted')) {
            $query->where('is_deleted', 0);
        }

        // Apply filters
        $where = $structured['where'] ?? null;
        if ($where) {
            $filters = [];
            if (isset($where['fieldFilter'])) {
                $filters[] = $where['fieldFilter'];
            } elseif (isset($where['compositeFilter']['filters'])) {
                foreach ($where['compositeFilter']['filters'] as $f) {
                    if (isset($f['fieldFilter'])) {
                        $filters[] = $f['fieldFilter'];
                    }
                }
            }

            foreach ($filters as $filter) {
                $fieldPath = $filter['field']['fieldPath'] ?? '';
                $op = $filter['op'] ?? 'EQUAL';
                $val = self::decodeValue($filter['value'] ?? null);

                // Map field to column
                $col = null;
                if ($fieldPath === '__name__' || $fieldPath === 'id') {
                    $col = 'id';
                } elseif ($fieldPath === 'scopeType' && Schema::hasColumn($table, 'scope_type')) {
                    $col = 'scope_type';
                } elseif ($fieldPath === 'businessId' && Schema::hasColumn($table, 'business_id')) {
                    $col = 'business_id';
                } elseif ($fieldPath === 'outletId' && Schema::hasColumn($table, 'outlet_id')) {
                    $col = 'outlet_id';
                } elseif ($fieldPath === 'tenantId' && Schema::hasColumn($table, 'tenant_id')) {
                    $col = 'tenant_id';
                } elseif ($fieldPath === 'status' && Schema::hasColumn($table, 'status')) {
                    $col = 'status';
                } elseif ($fieldPath === 'updatedAt' && Schema::hasColumn($table, 'updated_at')) {
                    $col = 'updated_at';
                } elseif ($fieldPath === 'createdAt' && Schema::hasColumn($table, 'created_at')) {
                    $col = 'created_at';
                } elseif (Schema::hasColumn($table, $fieldPath)) {
                    $col = $fieldPath;
                }

                if ($col) {
                    switch ($op) {
                        case 'EQUAL':
                            if (Schema::hasColumn($table, 'payload') && is_string($val)) {
                                $query->where(function ($sub) use ($col, $val, $fieldPath) {
                                    $sub->where($col, '=', $val)
                                        ->orWhere(function ($fallback) use ($col, $val, $fieldPath) {
                                            $fallback->whereNull($col)
                                                ->whereRaw(
                                                    "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.{$fieldPath}.stringValue')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.{$fieldPath}'))) = ?",
                                                    [$val]
                                                );
                                        });
                                });
                            } else {
                                $query->where($col, '=', $val);
                            }
                            break;
                        case 'NOT_EQUAL':
                            $query->where($col, '!=', $val);
                            break;
                        case 'GREATER_THAN':
                            $query->where($col, '>', $val);
                            break;
                        case 'GREATER_THAN_OR_EQUAL':
                            $query->where($col, '>=', $val);
                            break;
                        case 'LESS_THAN':
                            $query->where($col, '<', $val);
                            break;
                        case 'LESS_THAN_OR_EQUAL':
                            $query->where($col, '<=', $val);
                            break;
                        case 'IN':
                            if (is_array($val)) {
                                $query->whereIn($col, $val);
                            }
                            break;
                        case 'NOT_IN':
                            if (is_array($val)) {
                                $query->whereNotIn($col, $val);
                            }
                            break;
                        default:
                            $query->where($col, '=', $val);
                    }
                } elseif (Schema::hasColumn($table, 'payload') && is_string($val)) {
                    // Filter in payload JSON
                    $query->whereRaw(
                        "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.{$fieldPath}.stringValue')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.{$fieldPath}'))) = ?",
                        [$val]
                    );
                }
            }
        }

        $orderBy = $structured['orderBy'] ?? [];

        // Apply Firestore startAt cursor semantics. Sync pages are ordered by
        // updatedAt + document name for incremental reads, or document name
        // alone for initial snapshots. Ignoring this cursor repeats page one.
        $cursorValues = $structured['startAt']['values'] ?? null;
        if (is_array($cursorValues) && !empty($cursorValues) && is_array($orderBy)) {
            $cursorId = null;
            $cursorUpdatedAt = null;

            foreach ($orderBy as $index => $order) {
                $fieldPath = $order['field']['fieldPath'] ?? '';
                $value = $cursorValues[$index] ?? null;

                if (($fieldPath === '__name__' || $fieldPath === 'id') && is_array($value)) {
                    $reference = $value['referenceValue'] ?? null;
                    if (is_string($reference) && $reference !== '') {
                        $cursorId = $this->parsePath($reference)['id'] ?: null;
                    }
                }
                if ($fieldPath === 'updatedAt' && is_array($value)) {
                    $timestamp = $value['timestampValue'] ?? null;
                    if (is_string($timestamp) && $timestamp !== '') {
                        $cursorUpdatedAt = Carbon::parse($timestamp);
                    }
                }
            }

            $before = (bool) ($structured['startAt']['before'] ?? false);
            $operator = $before ? '<' : '>';
            if ($cursorUpdatedAt !== null && Schema::hasColumn($table, 'updated_at')) {
                $query->where(function ($nested) use (
                    $cursorUpdatedAt,
                    $cursorId,
                    $operator,
                ) {
                    $nested->where('updated_at', $operator, $cursorUpdatedAt);
                    if ($cursorId !== null) {
                        $nested->orWhere(function ($tie) use (
                            $cursorUpdatedAt,
                            $cursorId,
                            $operator,
                        ) {
                            $tie->where('updated_at', '=', $cursorUpdatedAt)
                                ->where('id', $operator, $cursorId);
                        });
                    }
                });
            } elseif ($cursorId !== null) {
                $query->where('id', $operator, $cursorId);
            }
        }

        // Apply orderBy
        if (!empty($orderBy)) {
            foreach ($orderBy as $order) {
                $fieldPath = $order['field']['fieldPath'] ?? '';
                $dir = strtoupper($order['direction'] ?? 'ASCENDING') === 'DESCENDING' ? 'desc' : 'asc';
                $col = null;
                if ($fieldPath === '__name__' || $fieldPath === 'id') {
                    $col = 'id';
                } elseif ($fieldPath === 'updatedAt' && Schema::hasColumn($table, 'updated_at')) {
                    $col = 'updated_at';
                } elseif ($fieldPath === 'createdAt' && Schema::hasColumn($table, 'created_at')) {
                    $col = 'created_at';
                } elseif (Schema::hasColumn($table, $fieldPath)) {
                    $col = $fieldPath;
                }
                if ($col) {
                    $query->orderBy($col, $dir);
                }
            }
        }

        $limit = isset($structured['limit']) ? min((int) $structured['limit'], 1000) : 500;
        $rows = $query->limit($limit)->get();

        if ($rows->isEmpty()) {
            return response()->json([
                ['readTime' => $now],
            ]);
        }

        $results = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '', true) ?: [];
            $rawFields = $payload['fields'] ?? [];
            if (empty($rawFields)) {
                if ($table === 'users') {
                    $rawFields = $this->synthesizeUserFields($row);
                } else {
                    $rawFields = self::encodeFields((array) $row);
                }
            } else {
                $rawFields = self::formatFirestoreFields($rawFields);
            }
            $updateTime = $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : $now;
            $createTime = $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : $now;

            $results[] = [
                'document' => [
                    'name' => "projects/nubibilling-local/databases/(default)/documents/{$from}/{$row->id}",
                    'fields' => $rawFields,
                    'createTime' => $createTime,
                    'updateTime' => $updateTime,
                ],
                'readTime' => $now,
            ];
        }

        return response()->json($results);
    }

    /**
     * Helper to parse collection and document id from a resource path.
     */
    private function parsePath(string $target): array
    {
        $target = trim($target);
        if (str_contains($target, '/documents/')) {
            $parts = explode('/documents/', $target, 2);
            $target = $parts[1] ?? '';
        }
        $segments = explode('/', $target);
        $collection = $segments[0] ?? '';
        $id = count($segments) > 1 ? urldecode(implode('/', array_slice($segments, 1))) : '';

        return [
            'collection' => $collection,
            'id' => $id,
        ];
    }
}
