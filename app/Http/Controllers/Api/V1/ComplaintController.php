<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComplaintController extends Controller
{
    /**
     * List complaints for current business or user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $request->query('business_id') ?? $user?->business_id;
        $userEmail = $request->query('user_email') ?? $user?->email;

        $query = DB::table('user_complaints');

        if ($businessId) {
            $query->where('business_id', $businessId);
        } elseif ($userEmail) {
            $query->where('user_email', $userEmail);
        }

        $complaints = $query->orderBy('created_at', 'desc')->limit(100)->get();

        $formatted = $complaints->map(function ($c) {
            $c->timeline = $c->timeline ? json_decode($c->timeline, true) : [];
            $c->system_logs = $c->system_logs ? json_decode($c->system_logs, true) : null;
            return $c;
        });

        return ApiResponse::success($formatted, 'Complaints retrieved.');
    }

    /**
     * Submit a new complaint.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'nullable|string|max:100',
            'priority' => 'nullable|string|max:50',
            'business_id' => 'nullable|string|max:255',
            'business_name' => 'nullable|string|max:255',
            'outlet_id' => 'nullable|string|max:255',
            'outlet_name' => 'nullable|string|max:255',
            'user_id' => 'nullable|string|max:255',
            'user_name' => 'nullable|string|max:255',
            'user_email' => 'nullable|string|max:255',
            'user_phone' => 'nullable|string|max:50',
            'page_name' => 'nullable|string|max:255',
            'device_platform' => 'nullable|string|max:100',
            'app_version' => 'nullable|string|max:100',
            'error_summary' => 'nullable|string',
            'error_details' => 'nullable|string',
            'system_logs' => 'nullable|array',
        ]);

        $user = $request->user();
        $id = (string) Str::uuid();
        $now = Carbon::now();

        // Generate human-friendly complaint code: CMP-10001, etc.
        $count = DB::table('user_complaints')->count();
        $code = 'CMP-' . str_pad((string)($count + 1001), 5, '0', STR_PAD_LEFT);

        $businessId = $validated['business_id'] ?? $user?->business_id;
        $outletId = $validated['outlet_id'] ?? $user?->outlet_id;
        $userEmail = $validated['user_email'] ?? $user?->email;
        $userName = $validated['user_name'] ?? $user?->name;
        $userId = $validated['user_id'] ?? ($user ? (string) $user->id : null);

        $initialTimeline = [
            [
                'stage' => 'SUBMITTED',
                'title' => 'Complaint Raised',
                'description' => 'Your complaint has been submitted and registered with ticket ' . $code,
                'timestamp' => $now->toIso8601String(),
                'is_completed' => true,
            ],
            [
                'stage' => 'UNDER_REVIEW',
                'title' => 'Under Review',
                'description' => 'Technical support is evaluating the details',
                'timestamp' => null,
                'is_completed' => false,
            ],
            [
                'stage' => 'IN_PROGRESS',
                'title' => 'In Progress',
                'description' => 'Engineering team is working on the resolution',
                'timestamp' => null,
                'is_completed' => false,
            ],
            [
                'stage' => 'RESOLVED',
                'title' => 'Resolved',
                'description' => 'Issue resolved and verified',
                'timestamp' => null,
                'is_completed' => false,
            ],
        ];

        $data = [
            'id' => $id,
            'complaint_code' => $code,
            'business_id' => $businessId,
            'business_name' => $validated['business_name'] ?? null,
            'outlet_id' => $outletId,
            'outlet_name' => $validated['outlet_name'] ?? null,
            'user_id' => $userId,
            'user_name' => $userName,
            'user_email' => $userEmail,
            'user_phone' => $validated['user_phone'] ?? null,
            'subject' => $validated['subject'],
            'category' => $validated['category'] ?? 'GENERAL',
            'priority' => $validated['priority'] ?? 'MEDIUM',
            'description' => $validated['description'],
            'page_name' => $validated['page_name'] ?? null,
            'device_platform' => $validated['device_platform'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'error_summary' => $validated['error_summary'] ?? null,
            'error_details' => $validated['error_details'] ?? null,
            'system_logs' => isset($validated['system_logs']) ? json_encode($validated['system_logs']) : null,
            'status' => 'SUBMITTED',
            'admin_notes' => null,
            'resolution_summary' => null,
            'timeline' => json_encode($initialTimeline),
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('user_complaints')->insert($data);

        $data['timeline'] = $initialTimeline;

        return ApiResponse::success($data, 'Complaint registered successfully.');
    }

    /**
     * Show complaint by ID or complaint code.
     */
    public function show(string $id): JsonResponse
    {
        $complaint = DB::table('user_complaints')
            ->where('id', $id)
            ->orWhere('complaint_code', $id)
            ->first();

        if (! $complaint) {
            return ApiResponse::error('Complaint not found.', 404);
        }

        $complaint->timeline = $complaint->timeline ? json_decode($complaint->timeline, true) : [];
        $complaint->system_logs = $complaint->system_logs ? json_decode($complaint->system_logs, true) : null;

        return ApiResponse::success($complaint, 'Complaint details retrieved.');
    }
}
