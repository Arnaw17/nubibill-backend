<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:APPROVED,REJECTED,approved,rejected'],
            'reject_reason' => ['required_if:status,REJECTED,rejected', 'nullable', 'string', 'max:500'],
            'number' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
            'status.in' => 'Status must be APPROVED or REJECTED.',
            'reject_reason.required_if' => 'Rejection reason is required when rejecting a document.',
        ];
    }
}
