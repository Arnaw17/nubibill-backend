<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     * Note: 'status' is deliberately omitted - it must NEVER be mass assigned from client input.
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
        'role',
        'email_verified_at',
        'activated_at',
        'activated_by',
        'business_id',
        'version',
        'is_deleted',
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
        return strtoupper((string) $this->role) === 'ADMIN';
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
            'email_verified_at' => $this->email_verified_at?->toISOString(),
        ];
    }
}
