<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanInterestSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interest_rate' => 'required|numeric|min:0|max:100',
            'tenure_months' => 'nullable|integer|min:1|max:360',
            'active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'interest_rate.required' => 'Interest rate is required',
            'interest_rate.numeric' => 'Interest rate must be a number',
            'interest_rate.min' => 'Interest rate cannot be negative',
            'interest_rate.max' => 'Interest rate cannot exceed 100%',
        ];
    }
}
