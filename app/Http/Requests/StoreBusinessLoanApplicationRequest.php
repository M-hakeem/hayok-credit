<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBusinessLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nature_of_business' => ['required', 'string', 'max:255'],
            'product_use_case' => ['required', 'string', 'max:255'],
            'expected_monthly_transaction_volume' => ['required', 'integer', 'min:0'],
            'expected_monthly_transaction_value' => ['required', 'numeric', 'min:0'],
            'source_of_funds' => ['required', 'string', 'max:1000'],

            'business.registered_name' => ['required', 'string', 'max:255'],
            'business.trading_name' => ['nullable', 'string', 'max:255'],
            'business.registration_number' => ['required', 'string', 'max:100'],
            'business.type' => ['required', 'in:limited_liability_company,sole_proprietorship,partnership,ngo,public_company'],
            'business.industry' => ['required', 'string', 'max:255'],
            'business.incorporation_date' => ['required', 'date', 'before_or_equal:today'],
            'business.registration_country' => ['required', 'string', 'max:100'],
            'business.registration_state' => ['required', 'string', 'max:100'],
            'business.registered_office_address' => ['required', 'string', 'min:10'],
            'business.operating_address' => ['nullable', 'string', 'min:10'],
            'business.website' => ['nullable', 'url', 'max:255'],
            'business.social_handles' => ['nullable', 'array'],
            'business.tin' => ['required', 'string', 'max:100'],
            'business.email' => ['required', 'email', 'max:255'],
            'business.phone' => ['required', 'string', 'max:20'],

            'account_owner.first_name' => ['required', 'string', 'max:100'],
            'account_owner.last_name' => ['required', 'string', 'max:100'],
            'account_owner.date_of_birth' => ['required', 'date', 'before:today'],
            'account_owner.gender' => ['required', 'in:male,female'],
            'account_owner.nationality' => ['required', 'string', 'max:100'],
            'account_owner.identification_type' => ['required', 'in:BVN,NIN'],
            'account_owner.identification_number' => ['required', 'string', 'max:50'],
            'account_owner.government_id_number' => ['required', 'string', 'max:100'],
            'account_owner.government_id_expires_at' => ['required', 'date', 'after:today'],
            'account_owner.phone' => ['required', 'string', 'max:20'],
            'account_owner.email' => ['required', 'email', 'max:255'],
            'account_owner.residential_address' => ['required', 'string', 'min:10'],
            'account_owner.shareholding_percentage' => ['required', 'numeric', 'between:0,100'],
            'account_owner.role' => ['required', 'string', 'max:100'],

            'stakeholders' => ['required', 'array', 'min:1'],
            'stakeholders.*.full_name' => ['required', 'string', 'max:255'],
            'stakeholders.*.date_of_birth' => ['required', 'date', 'before:today'],
            'stakeholders.*.gender' => ['required', 'in:male,female'],
            'stakeholders.*.nationality' => ['required', 'string', 'max:100'],
            'stakeholders.*.identification_type' => ['required', 'in:BVN,NIN'],
            'stakeholders.*.identification_number' => ['required', 'string', 'max:50'],
            'stakeholders.*.identification_expires_at' => ['nullable', 'date', 'after:today'],
            'stakeholders.*.residential_address' => ['required', 'string', 'min:10'],
            'stakeholders.*.shareholding_percentage' => ['required', 'numeric', 'between:0,100'],
            'stakeholders.*.role' => ['required', 'in:director,shareholder,signatory,beneficial_owner'],
            'stakeholders.*.is_pep' => ['required', 'boolean'],
            'stakeholders.*.identity_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'stakeholders.*.proof_of_address' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            'documents' => ['required', 'array'],
            'documents.*.type' => ['required', 'string', 'max:100'],
            'documents.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.*.expires_at' => ['nullable', 'date'],

            'loan.amount_requested' => ['required', 'numeric', 'min:100'],
            'loan.term_months' => ['required', 'integer', 'between:1,60'],
            'loan.purpose' => ['required', 'string', 'max:1000'],
            'loan.existing_debt_obligations' => ['nullable', 'string', 'max:5000'],
            'loan.repayment_plan' => ['required', 'string', 'max:2000'],
            'loan.collateral_details' => ['nullable', 'string', 'max:5000'],
            'consent' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $requiredDocumentTypes = [
                'account_owner_identity_document',
                'account_owner_address_proof',
                'account_owner_passport_photograph',
                'certificate_of_incorporation',
                'memorandum_and_articles',
                'directors_status_report',
                'business_address_proof',
                'tin_certificate',
                'board_resolution',
                'financial_statements',
                'bank_statements',
            ];

            $submittedTypes = collect($this->input('documents', []))->pluck('type')->all();
            foreach ($requiredDocumentTypes as $type) {
                if (! in_array($type, $submittedTypes, true)) {
                    $validator->errors()->add('documents', "The {$type} document is required.");
                }
            }

            foreach ($this->input('stakeholders', []) as $index => $stakeholder) {
                if (($stakeholder['shareholding_percentage'] ?? 0) >= 10
                    && ! in_array($stakeholder['role'] ?? '', ['shareholder', 'beneficial_owner'], true)) {
                    $validator->errors()->add("stakeholders.{$index}.role", 'Stakeholders owning 10% or more must be recorded as a shareholder or beneficial owner.');
                }
            }
        });
    }
}
