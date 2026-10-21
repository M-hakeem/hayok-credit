<?php

namespace App\Services;

use App\Models\PhoneVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkSmsLiveOtpService
{
    public function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($phone)) ?? trim($phone);

        return str_starts_with($phone, '00') ? '+'.substr($phone, 2) : $phone;
    }

    public function requestOtp(string $phone, string $purpose = 'registration'): void
    {
        $phone = $this->normalizePhone($phone);
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(config('services.bulksmslive.otp_ttl_minutes', 5));

        $verification = PhoneVerification::withTrashed()->firstOrNew(['phone_number' => $phone]);
        if ($verification->exists && $verification->trashed()) {
            $verification->restore();
        }

        $verification->fill([
            'otp_hash' => Hash::make($otp),
            'verified' => false,
            'expires_at' => $expiresAt,
            'delivery_channel' => 'sms',
            'delivery_status' => 'pending',
            'attempt_count' => 0,
            'message_id' => null,
            'purpose' => $purpose,
        ]);
        $verification->save();

        $sent = $this->send($phone, $otp);
        $verification->update([
            'delivery_status' => $sent ? 'sent' : 'failed',
        ]);
    }

    public function verifyOtp(string $phone, string $otp): string
    {
        $verification = PhoneVerification::where('phone_number', $this->normalizePhone($phone))->first();

        if (! $verification || $verification->verified || ! $verification->otp_hash) {
            return 'invalid';
        }

        if (! $verification->expires_at || $verification->expires_at->isPast()) {
            $verification->update(['delivery_status' => 'expired']);

            return 'invalid';
        }

        if ($verification->attempt_count >= config('services.bulksmslive.otp_max_attempts', 5)) {
            return 'too_many_attempts';
        }

        if (! preg_match('/^\d{6}$/', $otp) || ! Hash::check($otp, $verification->otp_hash)) {
            $verification->increment('attempt_count');

            return $verification->fresh()->attempt_count >= config('services.bulksmslive.otp_max_attempts', 5)
                ? 'too_many_attempts'
                : 'invalid';
        }

        $verification->update([
            'verified' => true,
            'delivery_status' => 'verified',
        ]);

        return 'verified';
    }

    public function invalidate(PhoneVerification $verification): void
    {
        $verification->update([
            'otp_hash' => null,
            'verified' => false,
            'delivery_status' => 'expired',
        ]);
    }

    public function consume(PhoneVerification $verification): void
    {
        $verification->update([
            'otp_hash' => null,
            'delivery_status' => 'consumed',
        ]);
    }

    private function send(string $phone, string $otp): bool
    {
        $apiKey = config('services.bulksmslive.api_key');
        $usingApiKey = is_string($apiKey) && trim($apiKey) !== '';
        $payload = [
            'message' => 'Your Hayok Credit verification code is '.$otp,
            'sender_name' => config('services.bulksmslive.sender_name'),
            'recipients' => ltrim($phone, '+'),
            'forcednd' => config('services.bulksmslive.force_dnd', 1),
        ];
        if (! $usingApiKey) {
            $payload['email'] = config('services.bulksmslive.email');
            $payload['password'] = config('services.bulksmslive.password');
        }
        $url = config($usingApiKey ? 'services.bulksmslive.api_key_url' : 'services.bulksmslive.url');

        try {
            $request = Http::asJson()
                ->connectTimeout(config('services.bulksmslive.connect_timeout', 5))
                ->timeout(config('services.bulksmslive.timeout', 10));
            if ($usingApiKey) {
                $request = $request->withHeaders([
                    'Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ]);
            }
            $response = $request->post($url, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('BulkSMSLive message request connection failed', [
                'host' => parse_url((string) $url, PHP_URL_HOST) ?: 'unknown',
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        $body = $response->json();
        $providerStatus = is_array($body) ? strtolower((string) ($body['status'] ?? '')) : '';
        $accepted = $response->successful()
            && is_array($body)
            && (
                ($body['success'] ?? null) === true
                || in_array($providerStatus, ['1', 'success', 'successful', 'sent', 'queued', 'ok', '200'], true)
            );

        if (! $accepted) {
            $providerMessage = is_array($body) ? ($body['message'] ?? $body['error'] ?? null) : null;
            Log::warning('BulkSMSLive message request was not accepted', [
                'host' => parse_url((string) $url, PHP_URL_HOST) ?: 'unknown',
                'http_status' => $response->status(),
                'provider_message' => is_string($providerMessage) ? substr($providerMessage, 0, 300) : null,
            ]);
        }

        return $accepted;
    }
}
