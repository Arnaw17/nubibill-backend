<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'return_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'return_id',
        'original_bill_item_id',
        'product_id',
        'variant_id',
        'product_name',
        'hsn_code',
        'unit',
        'qty_returned',
        'unit_price',
        'discount_amount',
        'taxable_amount',
        'tax_rate',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'tax_amount',
        'line_total',
        'condition',
        'restock',
        'batch_number',
        'expiry_date',
    ];

    protected $casts = [
        'qty_returned' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'restock' => 'boolean',
        'expiry_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Parent return invoice.
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(ReturnModel::class, 'return_id', 'id');
    }

    /**
     * Related product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
