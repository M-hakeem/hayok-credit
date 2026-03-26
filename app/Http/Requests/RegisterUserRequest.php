<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterUserRequest extends FormRequest
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
            'fullname' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:10',
            'email' => 'nullable|email|unique:users,email',
            'residential_address' => 'nullable|string',
            'state' => 'nullable|string|max:100',
            'lga' => 'nullable|string|max:100',
            'bnv' => 'nullable|string|max:20',
        ];
    }
}
