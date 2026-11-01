<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     * Note: 'status', 'role', 'email_verified_at', 'activated_at' are deliberately
     * omitted - they must NEVER be mass assigned from client input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'photo_path',
        'password',
        'business_id',
        'email_verified_at',
        'version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'password' => 'hashed',
            'is_deleted' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    public function aadhaarDocument(): HasOne
    {
        return $this->hasOne(UserDocument::class)->where('type', 'AADHAAR');
    }

    public function panDocument(): HasOne
    {
        return $this->hasOne(UserDocument::class)->where('type', 'PAN');
    }

    public function voterDocument(): HasOne
    {
        return $this->hasOne(UserDocument::class)->where('type', 'VOTER');
    }

    public function isActive(): bool
    {
        return strtoupper((string) $this->status) === 'ACTIVE';
    }

    public function isAdmin(): bool
    {
        return in_array(strtoupper((string) $this->role), ['ADMIN', 'SUPER_ADMIN', 'DEVELOPER', 'SUPER_USER'], true);
    }

    public function isSuperUser(): bool
    {
        return in_array(strtoupper((string) $this->role), ['ADMIN', 'SUPER_ADMIN', 'DEVELOPER', 'SUPER_USER'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && $this->isActive();
    }

    /**
     * Get IDs of all businesses accessible to this user.
     *
     * @return list<string>
     */
    public function accessibleBusinessIds(): array
    {
        if ($this->isAdmin()) {
            return DB::table('businesses')
                ->where('is_deleted', 0)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        $userId = (string) $this->id;
        $businessIds = [];

        if (strtoupper((string) $this->role) === 'STAFF') {
            $employee = DB::table('employees')
                ->where('is_deleted', 0)
                ->get()
                ->first(function ($row) use ($userId): bool {
                    $payload = json_decode((string) $row->payload, true) ?: [];
                    $fields = $payload['fields'] ?? $payload;

                    return (string) ($fields['uid'] ?? '') === $userId;
                });

            if ($employee) {
                $payload = json_decode((string) $employee->payload, true) ?: [];
                $fields = $payload['fields'] ?? $payload;
                $assigned = $fields['businessIds'] ?? (isset($fields['businessId']) ? [$fields['businessId']] : []);
                if (is_array($assigned)) {
                    foreach ($assigned as $bId) {
                        $businessIds[] = (string) $bId;
                    }
                }
            }
        } else {
            // Owner or standard user: find businesses where owner_uid == userId
            $rows = DB::table('businesses')
                ->where('is_deleted', 0)
                ->get();

            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true) ?: [];
                $fields = $payload['fields'] ?? [];
                $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');
                if ($ownerUid === $userId) {
                    $businessIds[] = (string) ($fields['businessId'] ?? $row->business_id ?? $row->id);
                }
            }
        }

        return array_values(array_unique(array_filter($businessIds)));
    }

    public function canAccessBusiness(?string $businessId): bool
    {
        if ($businessId === null || $businessId === '') {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return in_array((string) $businessId, $this->accessibleBusinessIds(), true);
    }

    public function defaultBusinessId(): ?string
    {
        $ids = $this->accessibleBusinessIds();

        return $ids[0] ?? null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return route('api.v1.users.photo', ['id' => $this->id]);
    }

    /**
     * Format user array according to the Flutter app's AuthUser model.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'photo_url' => $this->photo_url,
            'status' => strtolower((string) $this->status),
            'role' => strtolower((string) $this->role),
            'is_superuser' => $this->isSuperUser(),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
        ];
    }
}
