<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuperUserReleaseController extends Controller
{
    /**
     * List all OTA releases and summary metrics for the SuperUser app.
     */
    public function index(): JsonResponse
    {
        $sorted = AppRelease::sortSemverDesc(AppRelease::query()->get());

        $activeReleases = $sorted->filter(fn (AppRelease $r): bool => $r->is_active && ! $r->is_recalled)->values();
        $latestActive = $activeReleases->first();

        return ApiResponse::success([
            'releases' => $sorted->map(fn (AppRelease $r): array => $r->toApiArray())->values()->all(),
            'summary' => [
                'totalReleases' => $sorted->count(),
                'activeReleases' => $activeReleases->count(),
                'recalledReleases' => $sorted->where('is_recalled', true)->count(),
                'totalDownloads' => (int) $sorted->sum('download_count'),
                'latestActiveVersion' => $latestActive?->version,
                'latestIsMandatory' => (bool) ($latestActive?->is_mandatory ?? false),
            ],
        ], 'OTA releases retrieved.');
    }

    /**
     * Upload and publish a new OTA release package.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => [
                'required',
                'string',
                'max:50',
                'regex:/^\d+\.\d+\.\d+([\-+][0-9A-Za-z\.\-]+)?$/',
            ],
            'channel' => ['required', 'string', Rule::in(['stable', 'beta', 'alpha'])],
            'platform' => ['required', 'string', 'max:50'],
            'is_mandatory' => ['nullable', 'boolean'],
            'min_supported_version' => ['nullable', 'string', 'max:50'],
            'database_migration_required' => ['nullable', 'boolean'],
            'target_db_version' => ['nullable', 'integer', 'min:1'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'package_file' => ['required', 'file', 'max:512000'], // 500MB max
        ]);

        $version = ltrim(trim($validated['version']), 'vV');
        $channel = trim($validated['channel']);
        $platform = trim($validated['platform']);

        $duplicate = AppRelease::query()
            ->where('version', $version)
            ->where('channel', $channel)
            ->where('platform', $platform)
            ->exists();

        if ($duplicate) {
            return ApiResponse::error(
                "Release v{$version} already exists for {$channel}/{$platform}.",
                422,
                ['version' => ["Release v{$version} already exists for {$channel}/{$platform}."]],
                'DUPLICATE_RELEASE',
            );
        }

        $file = $request->file('package_file');
        $originalName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $file->getClientOriginalName()) ?: "nubibill-v{$version}.zip";
        $fileSize = (int) $file->getSize();
        $checksum = hash_file('sha256', $file->getRealPath());
        $storedPath = $file->storeAs('releases', time().'_'.$originalName, 'private');

        if (! $storedPath) {
            return ApiResponse::error('Failed to store release package.', 500, null, 'STORAGE_FAILED');
        }

        $release = AppRelease::create([
            'version' => $version,
            'channel' => $channel,
            'platform' => $platform,
            'is_mandatory' => $request->boolean('is_mandatory'),
            'min_supported_version' => filled($validated['min_supported_version'] ?? null)
                ? AppRelease::normalizeVersion((string) $validated['min_supported_version'])
                : null,
            'database_migration_required' => $request->boolean('database_migration_required'),
            'target_db_version' => (int) ($validated['target_db_version'] ?? 1),
            'release_notes' => $validated['release_notes'] ?? '',
            'file_path' => $storedPath,
            'file_name' => $originalName,
            'file_size' => $fileSize,
            'checksum_sha256' => $checksum,
            'is_active' => true,
            'is_recalled' => false,
            'download_count' => 0,
            'published_at' => now(),
            'created_by' => $request->user()?->id,
        ]);

        return ApiResponse::success([
            'release' => $release->toApiArray(),
        ], "Release v{$release->version} published successfully.", 201);
    }

    /**
     * Toggle Forceful (Mandatory) vs Optional (Skippable) status.
     */
    public function toggleMandatory(Request $request, AppRelease $release): JsonResponse
    {
        $validated = $request->validate([
            'min_supported_version' => ['nullable', 'string', 'max:50'],
        ]);

        $newMandatory = ! $release->is_mandatory;
        $minSupported = $newMandatory
            ? (filled($validated['min_supported_version'] ?? null)
                ? AppRelease::normalizeVersion((string) $validated['min_supported_version'])
                : $release->min_supported_version)
            : null;

        $release->update([
            'is_mandatory' => $newMandatory,
            'min_supported_version' => $minSupported,
        ]);

        // When making a release optional, ensure older releases in the same channel/platform
        // do not keep blocking clients via intermediate mandatory checks.
        if (! $newMandatory) {
            $releaseNorm = AppRelease::normalizeVersion($release->version);
            $candidates = AppRelease::query()
                ->where('channel', $release->channel)
                ->where('platform', $release->platform)
                ->where('is_mandatory', true)
                ->get();

            foreach ($candidates as $candidate) {
                if (version_compare(AppRelease::normalizeVersion($candidate->version), $releaseNorm, '<')) {
                    $candidate->update([
                        'is_mandatory' => false,
                        'min_supported_version' => null,
                    ]);
                }
            }
        }

        return ApiResponse::success([
            'release' => $release->fresh()->toApiArray(),
        ], $newMandatory ? "Release v{$release->version} set to Mandatory." : "Release v{$release->version} set to Optional.");
    }

    /**
     * Enable or disable a release.
     */
    public function toggleActive(AppRelease $release): JsonResponse
    {
        $nextActive = ! $release->is_active;
        $release->update([
            'is_active' => $nextActive,
            // Re-activating clears recall state so SuperUser can restore a release if needed.
            'is_recalled' => $nextActive ? false : $release->is_recalled,
            'recall_reason' => $nextActive ? null : $release->recall_reason,
        ]);

        return ApiResponse::success([
            'release' => $release->fresh()->toApiArray(),
        ], $nextActive ? "Release v{$release->version} activated." : "Release v{$release->version} deactivated.");
    }

    /**
     * Recall a faulty release so affected clients are prompted to roll back.
     */
    public function recall(Request $request, AppRelease $release): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $release->update([
            'is_active' => false,
            'is_recalled' => true,
            'recall_reason' => $validated['reason'] ?? 'Recalled by SuperUser. Please roll back to the previous stable version.',
        ]);

        return ApiResponse::success([
            'release' => $release->fresh()->toApiArray(),
        ], "Release v{$release->version} recalled. Clients on this version will be prompted to roll back.");
    }

    /**
     * Permanently delete a release and its stored file.
     */
    public function destroy(AppRelease $release): JsonResponse
    {
        $disk = Storage::disk('private');
        if ($release->file_path && $disk->exists($release->file_path)) {
            $disk->delete($release->file_path);
        }

        $version = $release->version;
        $release->delete();

        return ApiResponse::success(null, "Release v{$version} deleted.");
    }
}
