<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class PartnerLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $address = $this->input('address', []);

        if (! is_array($address)) {
            return;
        }

        $legacyAddress = array_filter([
            'residential_address' => $this->input('residential_address'),
            'state' => $this->input('state'),
            'lga' => $this->input('lga'),
        ], fn ($value) => $value !== null);

        if ($legacyAddress !== []) {
            $this->merge(['address' => array_merge($legacyAddress, $address)]);
        }
    }

    public function rules(): array
    {
        return [
            'organisation_name'   => 'required|string|max:255',
            'phone_number'        => 'required|string',

            // Identity — required for new users (enforced in withValidator)
            'fullname'            => 'nullable|string|max:255',
            'email'               => 'nullable|email',
            'dob'                 => 'nullable|date',
            'gender'              => 'nullable|in:male,female',
            'nin'                 => 'nullable|string|max:11',
            'bvn'                 => 'nullable|string|max:11',

            // Address
            'address'                    => 'nullable|array',
            'address.residential_address' => 'nullable|string|min:10',
            'address.state'               => 'nullable|string|max:100',
            'address.lga'                 => 'nullable|string|max:100',

            // Bank
            'bank_name'           => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:64',
            'bank_account_name'   => 'required|string|max:255',
            'bank_code'           => 'nullable|string|max:32',

            // Loan
            'amount_requested'   => 'required|numeric|min:100|max:9999999.99',
            'term_months'        => 'required|integer|min:1|max:60',
            'application_reason' => 'nullable|string|max:1000',

            // Guarantors
            'guarantors'                  => 'nullable|array|min:1|max:3',
            'guarantors.*.guarantor_type' => 'required_with:guarantors|in:1st,2nd,3rd',
            'guarantors.*.name'           => 'required_with:guarantors|string|max:255',
            'guarantors.*.phone_number'   => 'required_with:guarantors|string|max:20',
            'guarantors.*.relationship'   => 'required_with:guarantors|string|max:100',
            'guarantors.*.id_type'        => 'required_with:guarantors|in:NIN,BVN,Drivers License,International Passport,Voters Card',
            'guarantors.*.id_file'        => 'required_with:guarantors|file|mimes:pdf,jpg,jpeg,png|max:5120',

            // Employment
            'employment'                        => 'nullable|array',
            'employment.employment_information' => 'required_with:employment|string|min:5',
            'employment.occupation'             => 'required_with:employment|string|min:3',
            'employment.educational_details'    => 'nullable|string|min:5',
            'employment.income'                 => 'required_with:employment|numeric|min:0',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $existingUser = User::where('phone_number', $this->phone_number)
                ->when($this->filled('email'), fn($q) => $q->orWhere('email', $this->email))
                ->first();

            if ($existingUser === null) {
                // New user — enforce required fields
                $required = [
                    'fullname', 'dob', 'gender', 'nin', 'bvn',
                    'address.residential_address',
                ];
                foreach ($required as $field) {
                    if (!$this->filled($field)) {
                        $validator->errors()->add($field, "The {$field} field is required.");
                    }
                }

                if (!$this->filled('guarantors')) {
                    $validator->errors()->add('guarantors', 'At least one guarantor is required.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'guarantors.*.guarantor_type.in' => 'Guarantor type must be 1st, 2nd, or 3rd.',
            'guarantors.*.id_type.in'        => 'ID type must be NIN, BVN, Drivers License, International Passport, or Voters Card.',
        ];
    }
}
