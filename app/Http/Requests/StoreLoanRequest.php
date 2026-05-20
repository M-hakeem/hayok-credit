<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_requested' => 'required|numeric|min:100|max:9999999.99',
            'term_months' => 'required|integer|min:1|max:60',
            'application_reason' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'amount_requested.required' => 'Loan amount is required',
            'amount_requested.numeric' => 'Loan amount must be a number',
            'amount_requested.min' => 'Loan amount must be at least 100',
            'term_months.required' => 'Loan term is required',
            'term_months.integer' => 'Loan term must be a whole number of months',
            'term_months.min' => 'Loan term must be at least 1 month',
            'term_months.max' => 'Loan term cannot exceed 60 months',
        ];
    }
}
