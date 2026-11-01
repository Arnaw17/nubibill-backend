<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'stock_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'qty_change' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'payload') && empty($movement->payload)) {
                $movement->payload = [
                    'fields' => [
                        'type' => $movement->type,
                        'reason' => $movement->note,
                        'productId' => $movement->variant_id ?? $movement->product_id,
                        'qtyBase' => (float) $movement->qty_change,
                        'balanceAfter' => (float) $movement->balance_after,
                        'outletId' => $movement->outlet_id,
                        'businessId' => $movement->business_id,
                        'referenceId' => $movement->reference_id,
                        'createdAt' => now()->toIso8601String(),
                    ],
                ];
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'document_path') && empty($movement->document_path)) {
                $movement->document_path = "stock_movements/{$movement->id}";
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
