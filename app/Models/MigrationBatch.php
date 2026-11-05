<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationBatch extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'migration_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'cutoff_date' => 'date',
        'date_from' => 'date',
        'date_to' => 'date',
        'total_records' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(MigrationFile::class, 'batch_id', 'id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MigrationLog::class, 'batch_id', 'id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(MigrationError::class, 'batch_id', 'id');
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(HistoricalStockAdjustment::class, 'batch_id', 'id');
    }
}
