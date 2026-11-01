<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ledger_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'date' => 'date',
        'is_reversal' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
