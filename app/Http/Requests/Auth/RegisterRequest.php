<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone'],
            'address' => ['required', 'string', 'max:500'],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Za-z]/',
                'regex:/[0-9]/',
                'confirmed',
            ],
            'photo' => ['nullable', 'file', 'image', 'max:2048'], // 2 MB max
            'aadhaar_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // 5 MB max
            'pan_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // 5 MB max
            'voter_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // 5 MB max
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'name.max' => 'Full name cannot exceed 120 characters.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'The email has already been taken.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Enter a valid 10-digit Indian phone number starting with 6-9.',
            'phone.unique' => 'The phone number has already been taken.',
            'address.required' => 'Residential address is required.',
            'address.max' => 'Address cannot exceed 500 characters.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must contain both letters and numbers.',
            'password.confirmed' => 'Passwords do not match.',
            'photo.image' => 'Profile photo must be an image.',
            'photo.max' => 'Profile photo cannot exceed 2 MB.',
            'aadhaar_file.required' => 'Please upload your Aadhaar card.',
            'aadhaar_file.mimes' => 'Aadhaar file must be a JPG, JPEG, PNG, or PDF.',
            'aadhaar_file.max' => 'Aadhaar file cannot exceed 5 MB.',
            'pan_file.required' => 'Please upload your PAN card.',
            'pan_file.mimes' => 'PAN file must be a JPG, JPEG, PNG, or PDF.',
            'pan_file.max' => 'PAN file cannot exceed 5 MB.',
            'voter_file.mimes' => 'Voter card file must be a JPG, JPEG, PNG, or PDF.',
            'voter_file.max' => 'Voter card file cannot exceed 5 MB.',
        ];
    }
}
