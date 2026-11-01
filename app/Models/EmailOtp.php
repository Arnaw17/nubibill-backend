<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'email',
        'code_hash',
        'expires_at',
        'attempts',
        'consumed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->attempts >= 5;
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
