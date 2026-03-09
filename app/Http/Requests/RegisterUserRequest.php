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
            'fullname' => 'required|string|max:255',
            'dob' => 'required|date',
            'gender' => 'required|string|max:10',
            'email' => 'required|email|unique:users,email',
            'residential_address' => 'required|string',
            'state' => 'required|string|max:100',
            'lga' => 'required|string|max:100',
            'bnv' => 'required|string|max:20',
            'phone_number' => 'required|string|max:15',
            'password' => 'required|string|min:6',
        ];
    }
}
