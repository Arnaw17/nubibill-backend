<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|string|in:sale_return,purchase_return',
            'party_id' => 'required|string',
            'party_name' => 'nullable|string|max:255',
            'party_phone' => 'nullable|string|max:50',
            'party_gstin' => 'nullable|string|max:25',
            'party_state_code' => 'nullable|string|max:10',
            'business_state_code' => 'nullable|string|max:10',
            'original_bill_id' => 'nullable|string',
            'original_bill_no' => 'nullable|string',
            'original_bill_date' => 'nullable|date',
            'return_date' => 'nullable|date',
            'reason_id' => 'nullable|string',
            'reason_note' => 'nullable|string',
            'refund_mode' => 'nullable|string|in:cash,upi,bank,adjust_to_ledger,credit_note',
            'idempotency_key' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string',
            'items.*.variant_id' => 'nullable|string',
            'items.*.original_bill_item_id' => 'nullable|string',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.hsn_code' => 'nullable|string|max:50',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.qty_returned' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|gte:0',
            'items.*.discount_amount' => 'nullable|numeric|gte:0',
            'items.*.tax_rate' => 'nullable|numeric|gte:0',
            'items.*.condition' => 'nullable|string|in:resalable,damaged,expired',
            'items.*.restock' => 'nullable|boolean',
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
        ];
    }
}
