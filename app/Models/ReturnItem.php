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

    /**
     * Get the resolved product name with variant if available.
     */
    public function getProductNameAttribute($value): string
    {
        $name = trim((string) $value);
        if ($name !== '' && strcasecmp($name, 'Item') !== 0) {
            return $name;
        }

        // Try to resolve from variant
        $variantId = $this->variant_id ?: $this->product_id;
        if ($variantId && str_starts_with($variantId, 'VAR-')) {
            $variant = \Illuminate\Support\Facades\DB::table('product_variants')->where('id', $variantId)->first();
            if ($variant) {
                $payload = json_decode((string) $variant->payload, true) ?: [];
                $f = $payload['fields'] ?? [];
                $pName = $f['productName'] ?? $f['name'] ?? null;
                $vName = $f['variantName'] ?? $f['displayName'] ?? null;
                if ($pName) {
                    return $pName . ($vName && $vName !== $pName ? " • {$vName}" : '');
                }
            }
        }

        // Try to resolve from product
        if ($this->product_id) {
            $product = \Illuminate\Support\Facades\DB::table('products')->where('id', $this->product_id)->first();
            if ($product) {
                $payload = json_decode((string) $product->payload, true) ?: [];
                $f = $payload['fields'] ?? [];
                if (! empty($f['name']) || ! empty($f['productName'])) {
                    return (string) ($f['name'] ?? $f['productName']);
                }
            }
        }

        // Try to resolve from linked invoice / purchase bill item
        $return = $this->relationLoaded('return') ? $this->return : $this->return()->first();
        if ($return && $return->original_bill_id) {
            $inv = \Illuminate\Support\Facades\DB::table('invoices')->where('id', $return->original_bill_id)->first();
            if ($inv) {
                $invPayload = json_decode((string) $inv->payload, true) ?: [];
                $items = $invPayload['fields']['items'] ?? [];
                foreach ($items as $it) {
                    if (($it['itemId'] ?? null) === $this->original_bill_item_id ||
                        ($it['productId'] ?? null) === $this->product_id ||
                        ($it['variantId'] ?? null) === $this->variant_id) {
                        $invName = $it['name'] ?? $it['variantName'] ?? $it['productName'] ?? null;
                        if ($invName && strcasecmp(trim($invName), 'Item') !== 0) {
                            return (string) $invName;
                        }
                    }
                }
            }
        }

        return $name !== '' ? $name : 'Item';
    }
}
