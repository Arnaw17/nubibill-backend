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
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('refund_mode') && is_string($this->refund_mode)) {
            $this->merge(['refund_mode' => strtolower(trim($this->refund_mode))]);
        }

        if ($this->has('type') && is_string($this->type)) {
            $this->merge(['type' => strtolower(trim($this->type))]);
        }

        $partyId = $this->input('party_id');
        if ($partyId === null || trim((string) $partyId) === '') {
            $this->merge(['party_id' => 'walk_in']);
        }

        if ($this->has('items') && is_array($this->items)) {
            $items = $this->items;
            foreach ($items as $idx => $item) {
                if (is_array($item)) {
                    if (empty($item['product_id']) && ! empty($item['original_bill_item_id'])) {
                        $items[$idx]['product_id'] = (string) $item['original_bill_item_id'];
                    }
                    if (isset($item['batch_number']) && trim((string) $item['batch_number']) === '') {
                        $items[$idx]['batch_number'] = null;
                    }
                    if (isset($item['expiry_date']) && trim((string) $item['expiry_date']) === '') {
                        $items[$idx]['expiry_date'] = null;
                    }
                }
            }
            $this->merge(['items' => $items]);
        }

        if ($this->has('original_bill_date') && trim((string) $this->original_bill_date) === '') {
            $this->merge(['original_bill_date' => null]);
        }

        if ($this->has('return_date') && trim((string) $this->return_date) === '') {
            $this->merge(['return_date' => null]);
        }

        if ($this->has('reason_id') && trim((string) $this->reason_id) === '') {
            $this->merge(['reason_id' => null]);
        }
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
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'party_id' => 'nullable|string',
            'party_name' => 'nullable|string|max:255',
            'party_phone' => 'nullable|string|max:50',
            'party_gstin' => 'nullable|string|max:25',
            'party_state_code' => 'nullable|string|max:10',
            'business_state_code' => 'nullable|string|max:10',
            'original_bill_id' => 'nullable|string',
            'original_bill_no' => 'nullable|string',
            'original_bill_date' => 'nullable|string',
            'return_date' => 'nullable|string',
            'reason_id' => 'nullable|string',
            'reason_note' => 'nullable|string',
            'refund_mode' => 'nullable|string|in:cash,upi,bank,adjust_to_ledger,credit_note',
            'idempotency_key' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|string',
            'items.*.variant_id' => 'nullable|string',
            'items.*.original_bill_item_id' => 'nullable|string',
            'items.*.product_name' => 'nullable|string|max:255',
            'items.*.hsn_code' => 'nullable|string|max:50',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.qty_returned' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'nullable|numeric|gte:0',
            'items.*.discount_amount' => 'nullable|numeric|gte:0',
            'items.*.tax_rate' => 'nullable|numeric|gte:0',
            'items.*.condition' => 'nullable|string|in:resalable,damaged,expired',
            'items.*.restock' => 'nullable|boolean',
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|string',
        ];
    }
}
