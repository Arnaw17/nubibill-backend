<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnResource extends JsonResource
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
            'business_id' => $this->business_id,
            'outlet_id' => $this->outlet_id,
            'return_no' => $this->return_no,
            'has_business_logo_snapshot' => (bool) ($this->has_business_logo_snapshot ?? false),
            'business_logo_url' => $this->business_logo_url ?? null,
            'business_logo_version' => $this->business_logo_version ?? null,
            'business_logo_hash' => $this->business_logo_hash ?? null,
            'has_business_signature_snapshot' => (bool) ($this->has_business_signature_snapshot ?? false),
            'business_signature_enabled' => (bool) ($this->business_signature_enabled ?? false),
            'business_signature_url' => $this->business_signature_url ?? null,
            'business_signature_version' => $this->business_signature_version ?? null,
            'business_signature_hash' => $this->business_signature_hash ?? null,
            'business_signatory_name' => $this->business_signatory_name ?? null,
            'business_signatory_designation' => $this->business_signatory_designation ?? null,
            'type' => $this->type,
            'original_bill_id' => $this->original_bill_id,
            'original_bill_no' => $this->original_bill_no,
            'original_bill_date' => $this->original_bill_date?->format('Y-m-d'),
            'party_id' => $this->party_id,
            'party_name' => $this->party_name,
            'party_phone' => $this->party_phone,
            'party_gstin' => $this->party_gstin,
            'party_state_code' => $this->party_state_code,
            'business_state_code' => $this->business_state_code,
            'return_date' => $this->return_date?->format('Y-m-d'),
            'reason_id' => $this->reason_id,
            'reason_name' => $this->reason?->name,
            'reason_note' => $this->reason_note,
            'refund_mode' => $this->refund_mode,
            'subtotal' => (float) $this->subtotal,
            'cgst_total' => (float) $this->cgst_total,
            'sgst_total' => (float) $this->sgst_total,
            'igst_total' => (float) $this->igst_total,
            'tax_total' => (float) $this->tax_total,
            'grand_total' => (float) $this->grand_total,
            'status' => $this->status,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => ReturnItemResource::collection($this->whenLoaded('items')),
            'stock_movements' => $this->whenLoaded('stockMovements'),
            'ledger_entries' => $this->whenLoaded('ledgerEntries'),
            'audits' => $this->whenLoaded('audits'),
        ];
    }
}
