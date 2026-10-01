<?php

namespace App\Services;

use App\Models\PhoneVerification;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class TermiiOtpService
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
        $expiresAt = now()->addMinutes(config('services.termii.otp_ttl_minutes', 5));

        $verification = PhoneVerification::withTrashed()->firstOrNew(['phone_number' => $phone]);
        if ($verification->exists && $verification->trashed()) {
            $verification->restore();
        }

        $oldId = $verification->id;
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

        if ($oldId) {
            $this->cache()->forget($this->otpCacheKey($oldId));
        }
        $this->cache()->put($this->otpCacheKey($verification->id), Crypt::encryptString($otp), $expiresAt);

        [$sent, $messageId] = $this->send($phone, $otp, 'sms');
        if ($sent) {
            $verification->update(['message_id' => $messageId, 'delivery_status' => 'pending']);

            return;
        }

        $this->sendWhatsAppFallback($verification, $otp);
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
        $this->cache()->forget($this->otpCacheKey($verification->id));

        return 'verified';
    }

    public function handleDeliveryStatus(string $token, array $payload): bool
    {
        $expectedToken = (string) config('services.termii.webhook_token', '');
        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return false;
        }

        $messageId = $payload['message_id'] ?? $payload['messageId'] ?? null;
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if (! $messageId) {
            return false;
        }

        $verification = PhoneVerification::where('message_id', $messageId)->first();
        if (! $verification || $verification->verified || $verification->delivery_status !== 'pending') {
            return true;
        }

        $failedStatuses = ['failed', 'failure', 'undelivered', 'not delivered', 'not_delivered', 'delivery_failed', 'rejected'];
        if (in_array($status, $failedStatuses, true)) {
            $lock = $this->cache()->lock('termii-otp-fallback:'.$verification->id, 30);
            if (! $lock->get()) {
                return true;
            }

            try {
                $verification->update(['delivery_status' => 'sms_failed']);
                $encryptedOtp = $this->cache()->get($this->otpCacheKey($verification->id));
                if (! $encryptedOtp || $verification->expires_at?->isPast()) {
                    $verification->update(['delivery_status' => 'failed']);

                    return true;
                }

                $this->sendWhatsAppFallback($verification, Crypt::decryptString($encryptedOtp));
            } finally {
                $lock->release();
            }
        } elseif (in_array($status, ['delivered', 'success', 'successful'], true)) {
            $verification->update(['delivery_status' => 'delivered']);
            $this->cache()->forget($this->otpCacheKey($verification->id));
        }

        return true;
    }

    public function invalidate(PhoneVerification $verification): void
    {
        $this->cache()->forget($this->otpCacheKey($verification->id));
        $verification->update([
            'otp_hash' => null,
            'verified' => false,
            'delivery_status' => 'expired',
        ]);
    }

    public function consume(PhoneVerification $verification): void
    {
        $this->cache()->forget($this->otpCacheKey($verification->id));
        $verification->update([
            'otp_hash' => null,
            'delivery_status' => 'consumed',
        ]);
    }

    private function sendWhatsAppFallback(PhoneVerification $verification, string $otp): void
    {
        [$sent] = $this->send($verification->phone_number, $otp, 'whatsapp');
        $verification->update([
            'delivery_channel' => 'whatsapp',
            'delivery_status' => $sent ? 'sent' : 'failed',
        ]);
    }

    private function send(string $phone, string $otp, string $channel): array
    {
        $isWhatsApp = $channel === 'whatsapp';
        $payload = [
            'api_key' => config('services.termii.api_key'),
            'to' => ltrim($phone, '+'),
            'from' => $isWhatsApp
                ? config('services.termii.whatsapp_sender_id')
                : config('services.termii.sender_id'),
            'sms' => 'Your Hayok Credit verification code is '.$otp,
            'type' => 'plain',
            'channel' => config($isWhatsApp ? 'services.termii.whatsapp_channel' : 'services.termii.sms_channel'),
        ];

        try {
            $response = Http::asJson()
                ->connectTimeout(config('services.termii.connect_timeout', 5))
                ->timeout(config('services.termii.timeout', 10))
                ->post(rtrim(config('services.termii.base_url'), '/').'/api/sms/send', $payload);
        } catch (ConnectionException) {
            return [false, null];
        }

        $messageId = $response->json('message_id') ?? $response->json('messageId');

        return [$response->successful() && is_string($messageId) && $messageId !== '', $messageId];
    }

    private function otpCacheKey(int $verificationId): string
    {
        return 'termii-otp:'.hash('sha256', (string) $verificationId);
    }

    private function cache(): Repository
    {
        return Cache::store(config('services.termii.otp_cache_store', 'file'));
    }
}
