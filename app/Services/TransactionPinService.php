<?php

namespace App\Services;

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class TransactionPinService
{
    private const MAX_PIN_ATTEMPTS = 5;

    private const PIN_LOCKOUT_SECONDS = 900;

    public function verify(User $user, string $pin): void
    {
        if (! $user->hasTransactionPin()) {
            throw ValidationException::withMessages([
                'pin' => 'Set up a transaction PIN before making this transaction.',
            ]);
        }

        $key = $this->attemptKey($user);
        if (RateLimiter::tooManyAttempts($key, self::MAX_PIN_ATTEMPTS)) {
            throw new TooManyRequestsHttpException(
                RateLimiter::availableIn($key),
                'Too many incorrect PIN attempts. Please try again later.'
            );
        }

        if (! Hash::check($pin, $user->transaction_pin_hash)) {
            RateLimiter::hit($key, self::PIN_LOCKOUT_SECONDS);
            throw ValidationException::withMessages([
                'pin' => 'The transaction PIN is incorrect.',
            ]);
        }

        RateLimiter::clear($key);
    }

    public function sendResetOtp(User $user, TermiiOtpService $otpService): void
    {
        if (! $user->phone_verified_at || blank($user->phone_number)) {
            throw ValidationException::withMessages([
                'phone_number' => 'A verified phone number is required to reset your transaction PIN.',
            ]);
        }

        $otpService->requestOtp($user->phone_number, 'transaction_pin_reset');
    }

    public function resetWithOtp(
        User $user,
        string $otp,
        string $newPin,
        TermiiOtpService $otpService,
    ): void {
        if (! $user->phone_verified_at || blank($user->phone_number)) {
            throw ValidationException::withMessages([
                'phone_number' => 'A verified phone number is required to reset your transaction PIN.',
            ]);
        }

        $phone = $otpService->normalizePhone($user->phone_number);
        $result = DB::transaction(function () use ($user, $otp, $newPin, $phone) {
            $verification = PhoneVerification::where('phone_number', $phone)
                ->lockForUpdate()
                ->first();

            if (
                ! $verification
                || $verification->purpose !== 'transaction_pin_reset'
                || ! $verification->otp_hash
                || ! $verification->expires_at
                || $verification->expires_at->isPast()
            ) {
                return 'invalid';
            }

            $maxAttempts = (int) config('services.termii.otp_max_attempts', 5);
            if ($verification->attempt_count >= $maxAttempts) {
                return 'too_many_attempts';
            }

            if (! Hash::check($otp, $verification->otp_hash)) {
                $verification->increment('attempt_count');

                return $verification->fresh()->attempt_count >= $maxAttempts
                    ? 'too_many_attempts'
                    : 'invalid';
            }

            $user->setTransactionPin($newPin);
            $verification->update([
                'verified' => true,
                'otp_hash' => null,
                'delivery_status' => 'consumed',
            ]);

            return 'verified';
        });

        if ($result === 'too_many_attempts') {
            throw new TooManyRequestsHttpException(
                null,
                'Too many verification attempts. Request a new code and try again.'
            );
        }

        if ($result !== 'verified') {
            throw ValidationException::withMessages([
                'otp' => 'The verification code is invalid or expired. Request a new code and try again.',
            ]);
        }

        RateLimiter::clear($this->attemptKey($user));
    }

    private function attemptKey(User $user): string
    {
        return 'transaction-pin:'.$user->getKey();
    }
}
