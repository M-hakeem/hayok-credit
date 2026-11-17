<?php

namespace App\Services;

use App\Models\PhoneVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiOtpService
{
    public function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($phone)) ?? trim($phone);

        return str_starts_with($phone, '00') ? '+'.substr($phone, 2) : $phone;
    }

    public function requestOtp(string $phone, string $purpose = 'registration', string $channel = 'sms'): void
    {
        $phone = $this->normalizePhone($phone);
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(config('services.termii.otp_ttl_minutes', 5));

        $verification = PhoneVerification::withTrashed()->firstOrNew(['phone_number' => $phone]);
        if ($verification->exists && $verification->trashed()) {
            $verification->restore();
        }

        $verification->fill([
            'otp_hash' => Hash::make($otp),
            'verified' => false,
            'expires_at' => $expiresAt,
            'delivery_channel' => $channel,
            'delivery_status' => 'pending',
            'attempt_count' => 0,
            'message_id' => null,
            'purpose' => $purpose,
        ]);
        $verification->save();

        $sent = $this->send($phone, $otp, $channel);
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

        if ($verification->attempt_count >= config('services.termii.otp_max_attempts', 5)) {
            return 'too_many_attempts';
        }

        if (! preg_match('/^\d{6}$/', $otp) || ! Hash::check($otp, $verification->otp_hash)) {
            $verification->increment('attempt_count');

            return $verification->fresh()->attempt_count >= config('services.termii.otp_max_attempts', 5)
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

    private function send(string $phone, string $otp, string $deliveryChannel): bool
    {
        $apiKey = config('services.termii.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            Log::warning('Termii OTP request is missing required configuration', [
                'api_key_configured' => false,
            ]);

            return false;
        }

        if ($deliveryChannel === 'whatsapp') {
            $deviceId = config('services.termii.whatsapp_device_id');
            $templateId = config('services.termii.whatsapp_template_id');

            if (! is_string($deviceId) || trim($deviceId) === '' || ! is_string($templateId) || trim($templateId) === '') {
                Log::warning('Termii WhatsApp OTP request is missing required configuration', [
                    'device_id_configured' => is_string($deviceId) && trim($deviceId) !== '',
                    'template_id_configured' => is_string($templateId) && trim($templateId) !== '',
                ]);

                return false;
            }

            $payload = [
                'phone_number' => ltrim($phone, '+'),
                'device_id' => $deviceId,
                'template_id' => $templateId,
                'api_key' => $apiKey,
                'data' => ['otp' => $otp],
            ];
            $configuredUrl = config('services.termii.whatsapp_url');
            $defaultUrl = 'https://api.ng.termii.com/api/send/template';
        } else {
            $senderId = config('services.termii.sender_id');
            if (! is_string($senderId) || trim($senderId) === '') {
                Log::warning('Termii SMS request is missing required configuration', [
                    'sender_id_configured' => false,
                ]);

                return false;
            }

            $payload = [
                'api_key' => $apiKey,
                'to' => ltrim($phone, '+'),
                'from' => $senderId,
                'sms' => 'Your Hayok Credit verification code is '.$otp,
                'type' => config('services.termii.type', 'plain'),
                'channel' => config('services.termii.channel', 'generic'),
            ];
            $configuredUrl = config('services.termii.url');
            $defaultUrl = 'https://api.ng.termii.com/api/sms/send';
        }

        $url = is_string($configuredUrl) && trim($configuredUrl) !== ''
            ? trim($configuredUrl)
            : $defaultUrl;

        try {
            $request = Http::asJson()
                ->acceptJson()
                ->connectTimeout(config('services.termii.connect_timeout', 5))
                ->timeout(config('services.termii.timeout', 10));
            $response = $request->post($url, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('Termii OTP request connection failed', [
                'host' => parse_url((string) $url, PHP_URL_HOST) ?: 'unknown',
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        $body = $response->json();
        $providerCode = is_array($body) ? strtolower((string) ($body['code'] ?? '')) : '';
        $accepted = $response->successful()
            && is_array($body)
            && $providerCode === 'ok';

        if (! $accepted) {
            $providerMessage = is_array($body) ? ($body['message'] ?? $body['error'] ?? null) : null;
            Log::warning('Termii OTP request was not accepted', [
                'host' => parse_url((string) $url, PHP_URL_HOST) ?: 'unknown',
                'http_status' => $response->status(),
                'provider_message' => is_string($providerMessage) ? substr($providerMessage, 0, 300) : null,
            ]);
        }

        return $accepted;
    }
}
