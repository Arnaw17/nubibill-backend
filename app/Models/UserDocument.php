<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserDocument extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'type',
        'file_path',
        'number_encrypted',
        'status',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'number_encrypted' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->status) === 'APPROVED';
    }

    public function isPending(): bool
    {
        return strtoupper((string) $this->status) === 'PENDING';
    }

    public function isRejected(): bool
    {
        return strtoupper((string) $this->status) === 'REJECTED';
    }
}
