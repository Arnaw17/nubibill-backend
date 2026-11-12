<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppRelease extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'channel',
        'platform',
        'is_mandatory',
        'min_supported_version',
        'database_migration_required',
        'target_db_version',
        'release_notes',
        'file_path',
        'file_name',
        'file_size',
        'checksum_sha256',
        'is_active',
        'is_recalled',
        'recall_reason',
        'download_count',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'database_migration_required' => 'boolean',
            'is_active' => 'boolean',
            'is_recalled' => 'boolean',
            'target_db_version' => 'integer',
            'file_size' => 'integer',
            'download_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Strip SemVer build metadata (+build) before PHP version_compare.
     */
    public static function normalizeVersion(string $version): string
    {
        $trimmed = ltrim(trim($version), 'vV');

        return preg_replace('/\+.*$/', '', $trimmed) ?? $trimmed;
    }

    /**
     * Sort a collection of AppRelease models in descending SemVer order.
     *
     * @param  Collection<int, AppRelease>  $releases
     * @return Collection<int, AppRelease>
     */
    public static function sortSemverDesc(Collection $releases): Collection
    {
        return $releases->sort(function (self $a, self $b): int {
            $cmp = version_compare(
                self::normalizeVersion($b->version),
                self::normalizeVersion($a->version),
            );
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b->id <=> $a->id;
        })->values();
    }

    /**
     * Determine if this release requires a forced update for a given client version.
     */
    public function isForcedFor(string $clientVersion): bool
    {
        if ($this->is_mandatory) {
            return true;
        }

        if ($this->min_supported_version) {
            return version_compare(
                self::normalizeVersion($clientVersion),
                self::normalizeVersion($this->min_supported_version),
                '<',
            );
        }

        return false;
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = (float) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Serialize release metadata for API responses.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'channel' => $this->channel,
            'platform' => $this->platform,
            'is_mandatory' => (bool) $this->is_mandatory,
            'min_supported_version' => $this->min_supported_version,
            'database_migration_required' => (bool) $this->database_migration_required,
            'target_db_version' => (int) $this->target_db_version,
            'release_notes' => $this->release_notes ?? '',
            'file_name' => $this->file_name,
            'file_size' => (int) $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'checksum_sha256' => $this->checksum_sha256,
            'is_active' => (bool) $this->is_active,
            'is_recalled' => (bool) $this->is_recalled,
            'recall_reason' => $this->recall_reason,
            'download_count' => (int) $this->download_count,
            'download_url' => url('/api/v1/updates/download/'.$this->id),
            'published_at' => ($this->published_at ?? $this->created_at)?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
