<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fullname'             => 'sometimes|string|max:255',
            'dob'                  => 'sometimes|date',
            'gender'               => 'sometimes|string|in:male,female,other',
            'email'                => 'sometimes|email|max:255|unique:users,email,' . auth()->id(),
            'residential_address'  => 'sometimes|string|min:10|max:255',
            'state'                => 'sometimes|string|min:2|max:50',
            'lga'                  => 'sometimes|string|min:2|max:50',
            'nin'                  => 'sometimes|string|size:11',
            'bvn'                  => 'sometimes|string|size:11',
            'profile_image'        => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];
    }
}
