<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuarantorRequest extends FormRequest
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
            'guarantor_type' => 'required|in:1st,2nd,3rd',
            'relationship' => 'required|string|min:3|max:50',
            'name' => 'required|string|min:3|max:100',
            'phone_number' => 'required|string',
            'id_type' => 'required|in:NIN,BVN,Drivers License,International Passport,Voters Card',
            'id_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'guarantor_type.required' => 'Guarantor type is required',
            'guarantor_type.in' => 'Guarantor type must be 1st, 2nd, or 3rd',
            'relationship.required' => 'Relationship is required',
            'relationship.min' => 'Relationship must be at least 3 characters',
            'name.required' => 'Guarantor name is required',
            'name.min' => 'Guarantor name must be at least 3 characters',
            'phone_number.required' => 'Guarantor phone number is required',
            'id_type.required' => 'Means of identification is required',
            'id_type.in' => 'ID type must be NIN, BVN, Drivers License, International Passport, or Voters Card',
            'id_file.required' => 'ID document file is required',
            'id_file.mimes' => 'ID document must be a PDF, JPG, JPEG, or PNG file',
            'id_file.max' => 'ID document must not exceed 5MB',
        ];
    }
}
