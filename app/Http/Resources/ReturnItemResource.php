<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_id' => $this->return_id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'original_bill_item_id' => $this->original_bill_item_id,
            'product_name' => $this->product_name,
            'hsn_code' => $this->hsn_code,
            'unit' => $this->unit,
            'qty_returned' => (float) $this->qty_returned,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'taxable_amount' => (float) $this->taxable_amount,
            'tax_rate' => (float) $this->tax_rate,
            'cgst_amount' => (float) $this->cgst_amount,
            'sgst_amount' => (float) $this->sgst_amount,
            'igst_amount' => (float) $this->igst_amount,
            'tax_amount' => (float) $this->tax_amount,
            'line_total' => (float) $this->line_total,
            'condition' => $this->condition,
            'restock' => (bool) $this->restock,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
        ];
    }
}
