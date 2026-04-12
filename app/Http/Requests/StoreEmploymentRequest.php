<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmploymentRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employment_information' => 'required|string|min:5|max:1000',
            'occupation' => 'required|string|min:3|max:100',
            'educational_details' => 'required|string|min:5|max:1000',
            'income' => 'required|numeric|min:0|max:999999.99',
            'bank_statement' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'employment_information.required' => 'Employment information is required',
            'employment_information.min' => 'Employment information must be at least 20 characters',
            'occupation.required' => 'Occupation is required',
            'occupation.min' => 'Occupation must be at least 3 characters',
            'educational_details.required' => 'Educational details are required',
            'educational_details.min' => 'Educational details must be at least 20 characters',
            'income.required' => 'Income information is required',
            'income.numeric' => 'Income must be a valid number',
            'income.min' => 'Income cannot be negative',
            'bank_statement.required' => 'Bank statement is required for verification',
            'bank_statement.mimes' => 'Bank statement must be a PDF or image file (jpg, jpeg, png)',
            'bank_statement.max' => 'Bank statement size must not exceed 5MB',
        ];
    }
}
