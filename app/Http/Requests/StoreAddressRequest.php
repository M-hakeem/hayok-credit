<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
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
            'residential_address' => 'required|string|min:10|max:255',
            'state' => 'required|string|min:2|max:50',
            'lga' => 'required|string|min:2|max:50',
            'utility_bill' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'residential_address.required' => 'Residential address is required',
            'residential_address.min' => 'Residential address must be at least 10 characters',
            'state.required' => 'State is required',
            'lga.required' => 'LGA is required',
            'utility_bill.required' => 'Utility bill is required for verification',
            'utility_bill.mimes' => 'Utility bill must be a PDF or image file (jpg, jpeg, png)',
            'utility_bill.max' => 'Utility bill size must not exceed 5MB',
        ];
    }
}
