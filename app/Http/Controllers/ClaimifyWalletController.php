<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountLevelService;
use App\Services\ClaimifyWalletService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClaimifyWalletController extends Controller
{
    public function __construct(private readonly ClaimifyWalletService $claimify) {}

    public function banks()
    {
        return $this->call(fn () => $this->claimify->banks());
    }

    public function nameInquiry(Request $request)
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:32'],
            'account_number' => ['required', 'digits:10'],
        ]);

        return $this->call(fn () => $this->claimify->nameInquiry($data['bank_code'], $data['account_number']));
    }

    public function initiateVerification(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:NIN,BVN'],
            'number' => ['required', 'digits:11'],
        ]);

        Cache::put($this->pendingIdentityKey($request), [
            'type' => $data['type'],
            'number' => $data['number'],
        ], now()->addMinutes(30));

        return $this->call(fn () => $this->claimify->initiateVerification($data['type'], $data['number']));
    }

    public function validateVerification(Request $request, AccountLevelService $accountLevelService)
    {
        $data = $request->validate([
            'identity_id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:NIN,BVN'],
            'otp' => ['required', 'digits:6'],
        ]);

        try {
            $response = $this->claimify->validateVerification($data['identity_id'], $data['type'], $data['otp']);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }

        if ($this->isFailedVerification($response)) {
            return response()->json([
                'status' => 'error',
                'message' => data_get($response, 'message', 'Identity verification failed.'),
                'data' => $response,
            ], 422);
        }

        $pendingIdentity = Cache::get($this->pendingIdentityKey($request));
        if (! $pendingIdentity || $pendingIdentity['type'] !== $data['type']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your verification session expired. Please initiate verification again.',
            ], 422);
        }

        if (! $this->hasComparableIdentityBiodata($response)) {
            Cache::forget($this->pendingIdentityKey($request));

            return response()->json([
                'status' => 'error',
                'message' => 'The identity provider did not return the required name, date of birth, and gender details.',
            ], 422);
        }

        $user = $request->user();
        $column = strtolower($data['type']);
        $attributes = [
            $column => $pendingIdentity['number'],
            $column.'_verified_at' => now(),
        ];

        $otherIdentityType = $data['type'] === 'BVN' ? 'NIN' : 'BVN';
        $otherIdentityVerifiedAt = strtolower($otherIdentityType).'_verified_at';
        if ($user->{$otherIdentityVerifiedAt} !== null
            && ! $this->matchesVerifiedIdentityBiodata($response, $user, $otherIdentityType)) {
            Cache::forget($this->pendingIdentityKey($request));

            return response()->json([
                'status' => 'error',
                'message' => "{$data['type']} details do not match your previously verified {$otherIdentityType}.",
            ], 422);
        }

        $attributes = array_merge(
            $attributes,
            $this->identityProfileAttributes($response, $user, $data['type'])
        );

        $user->forceFill($attributes)->save();
        $accountLevelService->recalculate($user);

        Cache::put($this->verifiedIdentityKey($request), [
            'identity_id' => $data['identity_id'],
            'type' => $data['type'],
        ], now()->addMinutes(30));
        Cache::forget($this->pendingIdentityKey($request));

        return response()->json(['status' => 'success', 'data' => $response]);
    }

    public function createCustomer(Request $request)
    {
        $user = $request->user();
        $identity = Cache::get($this->verifiedIdentityKey($request));

        if (! $identity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Verify your NIN or BVN before creating a wallet.',
            ], 422);
        }

        $number = $identity['type'] === 'NIN' ? $user->nin : $user->bvn;

        if (! $number) {
            return response()->json([
                'status' => 'error',
                'message' => "Your {$identity['type']} is not saved on your profile.",
            ], 422);
        }

        if (! $user->email || ! $user->phone_number) {
            return response()->json([
                'status' => 'error',
                'message' => 'An email address and phone number are required to create a wallet.',
            ], 422);
        }

        $payload = [
            'identityId' => $identity['identity_id'],
            // Claimify's create-customer endpoint identifies the validated record as vID.
            'identityType' => 'vID',
            'identityNumber' => $number,
            'reference' => 'HC_'.Str::upper(Str::random(16)),
            'email' => $user->email,
            'phone_number' => $user->phone_number,
        ];

        try {
            $response = $this->claimify->createCustomer($payload);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }

        $user->wallet()->firstOrCreate([], [
            'balance' => 0,
            'currency' => 'NGN',
            'status' => 'active',
        ]);

        return response()->json(['status' => 'success', 'data' => $response], 201);
    }

    private function call(callable $callback, int $status = 200)
    {
        try {
            return response()->json(['status' => 'success', 'data' => $callback()], $status);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }
    }

    private function upstreamError(RequestException $exception)
    {
        $response = $exception->response;

        return response()->json([
            'status' => 'error',
            'message' => data_get($response->json(), 'message', 'Claimify could not process this request.'),
            'data' => $response->json(),
        ], $response->status() >= 400 && $response->status() < 500 ? $response->status() : 502);
    }

    private function verifiedIdentityKey(Request $request): string
    {
        return 'claimify:verified-identity:'.$request->user()->id;
    }

    private function pendingIdentityKey(Request $request): string
    {
        return 'claimify:pending-identity:'.$request->user()->id;
    }

    private function isFailedVerification(array $response): bool
    {
        return data_get($response, 'success') === false
            || data_get($response, 'status') === false
            || data_get($response, 'requestSuccessful') === false
            || in_array(strtolower((string) data_get($response, 'status')), ['error', 'failed', 'failure'], true);
    }

    private function identityProfileAttributes(array $response, User $user, string $identityType): array
    {
        $providerResponse = $this->getProviderResponse($response);
        if (! is_array($providerResponse)) {
            Log::warning("{$identityType} verification response did not include provider profile data", [
                'user_id' => $user->id,
            ]);

            return [];
        }

        $columnPrefix = strtolower($identityType);
        $firstName = $this->normalizedBiodataValue($providerResponse['firstName'] ?? null);
        $middleName = $this->normalizedBiodataValue($providerResponse['middleName'] ?? null);
        $lastName = $this->normalizedBiodataValue($providerResponse['lastName'] ?? null);
        $firstName = mb_substr($firstName, 0, 255);
        $middleName = mb_substr($middleName, 0, 255);
        $lastName = mb_substr($lastName, 0, 255);
        $nameParts = array_filter([$firstName, $middleName, $lastName]);
        $fullname = implode(' ', $nameParts);
        if ($fullname === '' && is_string($providerResponse['fullName'] ?? null)) {
            $fullname = trim($providerResponse['fullName']);
        }

        $attributes = [
            $columnPrefix.'_first_name' => $firstName !== '' ? $firstName : null,
            $columnPrefix.'_middle_name' => $middleName !== '' ? $middleName : null,
            $columnPrefix.'_last_name' => $lastName !== '' ? $lastName : null,
            $columnPrefix.'_date_of_birth' => $this->normalizeDateOfBirth($providerResponse['dateOfBirth'] ?? $providerResponse['dob'] ?? null),
            $columnPrefix.'_gender' => $this->normalizeGender($providerResponse['gender'] ?? null),
        ];
        if ($fullname !== '') {
            $attributes['fullname'] = mb_substr($fullname, 0, 255);
        }

        if ($attributes[$columnPrefix.'_date_of_birth'] !== null) {
            $attributes['dob'] = $attributes[$columnPrefix.'_date_of_birth'];
        }

        if ($attributes[$columnPrefix.'_gender'] !== null) {
            $attributes['gender'] = $attributes[$columnPrefix.'_gender'];
        }

        $emailValue = $providerResponse['email']
            ?? $providerResponse['emailAddress']
            ?? $providerResponse['email_address']
            ?? null;
        $email = is_string($emailValue) ? strtolower(trim($emailValue)) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailInUse = User::withTrashed()
                ->where('email', $email)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($emailInUse) {
                Log::warning("{$identityType} email is already assigned to another account; profile email was not updated", [
                    'user_id' => $user->id,
                ]);
            } else {
                $attributes['email'] = $email;
            }
        }

        return $attributes;
    }

    private function matchesVerifiedIdentityBiodata(
        array $response,
        User $user,
        string $otherIdentityType
    ): bool {
        $providerResponse = $this->getProviderResponse($response);
        if (! is_array($providerResponse)) {
            return false;
        }

        $currentIdentity = [
            'first_name' => $this->normalizedBiodataValue($providerResponse['firstName'] ?? null),
            'middle_name' => $this->normalizedBiodataValue($providerResponse['middleName'] ?? null),
            'last_name' => $this->normalizedBiodataValue($providerResponse['lastName'] ?? null),
            'date_of_birth' => $this->normalizeDateOfBirth($providerResponse['dateOfBirth'] ?? $providerResponse['dob'] ?? null),
            'gender' => $this->normalizeGender($providerResponse['gender'] ?? null),
        ];
        $otherPrefix = strtolower($otherIdentityType);
        $other = [
            'first_name' => $user->{$otherPrefix.'_first_name'},
            'middle_name' => $user->{$otherPrefix.'_middle_name'},
            'last_name' => $user->{$otherPrefix.'_last_name'},
            'date_of_birth' => $user->{$otherPrefix.'_date_of_birth'},
            'gender' => $user->{$otherPrefix.'_gender'},
        ];

        if ($currentIdentity['first_name'] === ''
            || $currentIdentity['last_name'] === ''
            || $currentIdentity['date_of_birth'] === null
            || $currentIdentity['gender'] === null
            || ! filled($other['first_name'])
            || ! filled($other['last_name'])
            || ! filled($other['date_of_birth'])
            || ! filled($other['gender'])) {
            return false;
        }

        $namesMatch = $this->normalizeNameForComparison($currentIdentity['first_name'])
                === $this->normalizeNameForComparison($other['first_name'])
            && $this->normalizeNameForComparison($currentIdentity['last_name'])
                === $this->normalizeNameForComparison($other['last_name']);

        $middleNamesMatch = ! filled($currentIdentity['middle_name'])
            || ! filled($other['middle_name'])
            || $this->normalizeNameForComparison($currentIdentity['middle_name'])
                === $this->normalizeNameForComparison($other['middle_name']);

        return $namesMatch
            && $middleNamesMatch
            && $currentIdentity['date_of_birth'] === $other['date_of_birth']
            && $currentIdentity['gender'] === $other['gender'];
    }

    private function hasComparableIdentityBiodata(array $response): bool
    {
        $providerResponse = $this->getProviderResponse($response);

        return is_array($providerResponse)
            && $this->normalizedBiodataValue($providerResponse['firstName'] ?? null) !== ''
            && $this->normalizedBiodataValue($providerResponse['lastName'] ?? null) !== ''
            && $this->normalizeDateOfBirth($providerResponse['dateOfBirth'] ?? $providerResponse['dob'] ?? null) !== null
            && $this->normalizeGender($providerResponse['gender'] ?? null) !== null;
    }

    private function getProviderResponse(array $response): ?array
    {
        $providerResponse = data_get($response, 'data.token.data.providerResponse')
            ?? data_get($response, 'data.data.token.data.providerResponse');

        return is_array($providerResponse) ? $providerResponse : null;
    }

    private function normalizedBiodataValue(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value) ?? $value) : '';
    }

    private function normalizeNameForComparison(string $name): string
    {
        return mb_strtolower($this->normalizedBiodataValue($name));
    }

    private function normalizeDateOfBirth(mixed $dateOfBirth): ?string
    {
        if (! is_string($dateOfBirth)) {
            return null;
        }

        $dateOfBirth = trim($dateOfBirth);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $dateOfBirth);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($format) === $dateOfBirth) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeGender(mixed $gender): ?string
    {
        $gender = mb_strtolower($this->normalizedBiodataValue($gender));

        return match ($gender) {
            'm', 'male' => 'male',
            'f', 'female' => 'female',
            'o', 'other' => 'other',
            default => null,
        };
    }
}
