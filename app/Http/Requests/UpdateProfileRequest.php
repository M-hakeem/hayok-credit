<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fullname'      => 'sometimes|string|max:255',
            'dob'           => 'sometimes|date',
            'gender'        => 'sometimes|string|in:male,female,other',
            'email'         => 'sometimes|email|max:255|unique:users,email,' . auth()->id(),
            'phone_number'  => 'sometimes|string|min:10|max:20',
            'profile_image' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->validated() !== []) {
                return;
            }

            $validator->errors()->add(
                'profile',
                'Provide at least one supported profile field to update.'
            );
        });
    }
}
