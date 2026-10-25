<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class CreateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'businessName' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'business_type_id' => ['sometimes', 'required', 'string'],
            'businessTypeId' => ['sometimes', 'required', 'string'],
            'email' => ['required', 'string', 'email'],
            'phone' => ['required', 'string', 'min:10', 'max:15'],
            'address' => ['required', 'string', 'min:3'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i'],
            'logo' => ['nullable'],
            'logo_base64' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'Business name is required.',
            'businessName.required' => 'Business name is required.',
            'business_type_id.required' => 'Select a valid business type.',
            'businessTypeId.required' => 'Select a valid business type.',
            'email.required' => 'Business email address is required.',
            'email.email' => 'Enter a valid business email address.',
            'phone.required' => 'Contact phone number is required.',
            'address.required' => 'Business address is required.',
            'gstin.regex' => 'Enter a valid 15-character GSTIN format.',
        ];
    }

    public function getBusinessName(): string
    {
        return (string) ($this->input('business_name') ?? $this->input('businessName'));
    }

    public function getBusinessTypeId(): string
    {
        return (string) ($this->input('business_type_id') ?? $this->input('businessTypeId'));
    }
}
