<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuarantorIdDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_type' => 'required|in:NIN,BVN,Drivers License,International Passport,Voters Card',
            'id_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'id_type.required' => 'Means of identification is required.',
            'id_type.in'       => 'ID type must be NIN, BVN, Drivers License, International Passport, or Voters Card.',
            'id_file.required' => 'ID document file is required.',
            'id_file.mimes'    => 'ID document must be a PDF, JPG, JPEG, or PNG file.',
            'id_file.max'      => 'ID document must not exceed 5MB.',
        ];
    }
}
