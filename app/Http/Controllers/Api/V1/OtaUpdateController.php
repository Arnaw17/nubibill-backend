<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OtaUpdateController extends Controller
{
    /**
     * Public OTA update check for NubiBill clients.
     *
     * GET /api/v1/updates/check?version=1.0.0&platform=win32-x64&channel=stable
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'platform' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'in:stable,beta,alpha'],
        ]);

        $clientVersion = AppRelease::normalizeVersion($validated['version']);
        $clientPlatform = trim((string) ($validated['platform'] ?? 'win32-x64'));
        $channel = trim((string) ($validated['channel'] ?? 'stable'));

        $matchingPlatformScope = static function ($query) use ($clientPlatform): void {
            $query->where('platform', 'all')
                ->orWhere('platform', $clientPlatform);
        };

        // 1. Check whether the client's current version has been recalled by SuperUser.
        $recalledCurrent = AppRelease::query()
            ->where('channel', $channel)
            ->where($matchingPlatformScope)
            ->where('is_recalled', true)
            ->get()
            ->first(fn (AppRelease $r): bool => AppRelease::normalizeVersion($r->version) === $clientVersion);

        // 2. Fetch active, non-recalled candidate releases.
        $activeReleases = AppRelease::query()
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->where('channel', $channel)
            ->where($matchingPlatformScope)
            ->get();

        $sortedReleases = AppRelease::sortSemverDesc($activeReleases);

        if ($recalledCurrent) {
            // Prefer the newest active safe release older than the recalled version,
            // or any newest safe active release as fallback.
            $rollbackTarget = $sortedReleases->first(
                fn (AppRelease $r): bool => version_compare(
                    AppRelease::normalizeVersion($r->version),
                    $clientVersion,
                    '<',
                ),
            ) ?? $sortedReleases->first();

            if ($rollbackTarget) {
                return ApiResponse::success([
                    'update_available' => true,
                    'is_recalled' => true,
                    'rollback_recommended' => true,
                    'recall_reason' => $recalledCurrent->recall_reason ?: 'This release was recalled due to an issue. Please roll back to the previous stable version.',
                    'current_version' => $clientVersion,
                    'latest_version' => $rollbackTarget->version,
                    'is_mandatory' => true,
                    'force_update' => true,
                    'min_supported_version' => $rollbackTarget->min_supported_version,
                    'database_migration_required' => false,
                    'target_db_version' => (int) $rollbackTarget->target_db_version,
                    'release_notes' => $rollbackTarget->release_notes ?? '',
                    'download_url' => url('/api/v1/updates/download/'.$rollbackTarget->id),
                    'file_name' => $rollbackTarget->file_name,
                    'file_size' => (int) $rollbackTarget->file_size,
                    'checksum_sha256' => $rollbackTarget->checksum_sha256,
                    'published_at' => ($rollbackTarget->published_at ?? $rollbackTarget->created_at)?->toIso8601String(),
                    'rollback_target' => $rollbackTarget->toApiArray(),
                ], 'Current version has been recalled. Rollback recommended.');
            }
        }

        if ($sortedReleases->isEmpty()) {
            return ApiResponse::success([
                'update_available' => false,
                'is_recalled' => false,
                'rollback_recommended' => false,
                'current_version' => $clientVersion,
                'message' => 'No active releases found.',
            ], 'No active releases found.');
        }

        /** @var AppRelease $latestRelease */
        $latestRelease = $sortedReleases->first();
        $latestNorm = AppRelease::normalizeVersion($latestRelease->version);

        if (version_compare($clientVersion, $latestNorm, '>=')) {
            return ApiResponse::success([
                'update_available' => false,
                'is_recalled' => false,
                'rollback_recommended' => false,
                'current_version' => $clientVersion,
                'latest_version' => $latestRelease->version,
                'message' => 'Your application is up to date.',
            ], 'Application is up to date.');
        }

        // Determine mandatory status (target mandatory, below min_supported_version, or intermediate mandatory).
        $isMandatory = $latestRelease->isForcedFor($clientVersion);

        if (! $isMandatory) {
            $isMandatory = $sortedReleases->contains(function (AppRelease $release) use ($clientVersion, $latestNorm): bool {
                $ver = AppRelease::normalizeVersion($release->version);

                return version_compare($ver, $clientVersion, '>')
                    && version_compare($ver, $latestNorm, '<=')
                    && $release->is_mandatory;
            });
        }

        $databaseMigrationRequired = (bool) $latestRelease->database_migration_required;
        if (! $databaseMigrationRequired) {
            $databaseMigrationRequired = $sortedReleases->contains(function (AppRelease $release) use ($clientVersion, $latestNorm): bool {
                $ver = AppRelease::normalizeVersion($release->version);

                return version_compare($ver, $clientVersion, '>')
                    && version_compare($ver, $latestNorm, '<=')
                    && $release->database_migration_required;
            });
        }

        return ApiResponse::success([
            'update_available' => true,
            'is_recalled' => false,
            'rollback_recommended' => false,
            'current_version' => $clientVersion,
            'latest_version' => $latestRelease->version,
            'is_mandatory' => $isMandatory,
            'force_update' => $isMandatory,
            'min_supported_version' => $latestRelease->min_supported_version,
            'database_migration_required' => $databaseMigrationRequired,
            'target_db_version' => (int) $latestRelease->target_db_version,
            'release_notes' => $latestRelease->release_notes ?? '',
            'download_url' => url('/api/v1/updates/download/'.$latestRelease->id),
            'file_name' => $latestRelease->file_name,
            'file_size' => (int) $latestRelease->file_size,
            'checksum_sha256' => $latestRelease->checksum_sha256,
            'published_at' => ($latestRelease->published_at ?? $latestRelease->created_at)?->toIso8601String(),
        ], 'Update available.');
    }

    /**
     * Public list of active non-recalled releases (supports server-assisted rollback).
     *
     * GET /api/v1/updates/releases?platform=win32-x64&channel=stable
     */
    public function releases(Request $request): JsonResponse
    {
        $platform = trim((string) $request->query('platform', 'win32-x64'));
        $channel = trim((string) $request->query('channel', 'stable'));

        $releases = AppRelease::query()
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->where('channel', $channel)
            ->where(function ($query) use ($platform): void {
                $query->where('platform', 'all')
                    ->orWhere('platform', $platform);
            })
            ->get();

        $sorted = AppRelease::sortSemverDesc($releases)
            ->map(fn (AppRelease $release): array => $release->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success([
            'releases' => $sorted,
        ], 'Active releases retrieved.');
    }

    /**
     * Public binary/package download endpoint.
     *
     * GET /api/v1/updates/download/{release}
     */
    public function download(AppRelease $release): BinaryFileResponse|JsonResponse
    {
        if (! $release->is_active || $release->is_recalled) {
            return ApiResponse::error(
                'This release is inactive or has been recalled.',
                403,
                null,
                'RELEASE_UNAVAILABLE',
            );
        }

        $disk = Storage::disk('private');
        if (! $disk->exists($release->file_path)) {
            return ApiResponse::error(
                'Release package file not found on server.',
                404,
                null,
                'RELEASE_FILE_MISSING',
            );
        }

        $release->increment('download_count');

        return response()->download($disk->path($release->file_path), $release->file_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Checksum-SHA256' => $release->checksum_sha256,
            'X-App-Version' => $release->version,
            'X-Database-Migration-Required' => $release->database_migration_required ? '1' : '0',
            'X-Target-DB-Version' => (string) $release->target_db_version,
        ]);
    }
}
