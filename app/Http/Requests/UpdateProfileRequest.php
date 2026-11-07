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
            'profile_image' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unsupportedFields = array_diff(array_keys($this->all()), ['profile_image']);
            foreach ($unsupportedFields as $field) {
                $validator->errors()->add(
                    $field,
                    'Personal details cannot be changed here. Use identity verification for personal details and the address endpoints with a utility bill for residential address changes.'
                );
            }

            if ($validator->errors()->isNotEmpty() || $this->hasFile('profile_image')) {
                return;
            }

            $validator->errors()->add(
                'profile',
                'Provide a profile_image to update your profile.'
            );
        });
    }
}
