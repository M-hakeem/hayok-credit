<?php

namespace Tests\Feature;

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneOtpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.termii.api_key' => 'test-key',
            'services.termii.base_url' => 'https://v3.api.termii.com',
            'services.termii.sender_id' => 'HayokCredit',
            'services.termii.whatsapp_sender_id' => 'HayokCredit',
            'services.termii.webhook_token' => 'test-webhook-token',
            'services.termii.otp_cache_store' => 'array',
        ]);
        Cache::store('array')->flush();
    }

    public function test_it_falls_back_to_whatsapp_with_the_same_hashed_otp(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'SMS failed'], 503)
            ->push(['message_id' => 'wa-message-1'], 200);

        $response = $this->postJson('/api/auth/send-otp', ['phone' => '+234 706-123-4567']);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $smsText = $requests[0]['sms'];
        $whatsAppText = $requests[1]['sms'];
        preg_match('/(\d{6})$/', $smsText, $matches);

        $response->assertAccepted()
            ->assertJsonMissingPath('data')
            ->assertDontSee($matches[1]);

        $this->assertSame($smsText, $whatsAppText);
        $this->assertSame(
            'https://v3.api.termii.com/api/sms/send',
            Http::recorded()->first()[0]->url()
        );
        $this->assertSame('dnd', Http::recorded()->first()[0]['channel']);
        $this->assertSame('whatsapp', Http::recorded()->last()[0]['channel']);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'verified' => false,
            'delivery_channel' => 'whatsapp',
            'delivery_status' => 'sent',
        ]);

        $verification = PhoneVerification::firstOrFail();
        $this->assertNotSame($matches[1], $verification->otp_hash);
        $this->assertTrue(Hash::check($matches[1], $verification->otp_hash));

        $this->postJson('/api/auth/verify-otp', [
            'phone' => '+2347061234567',
            'pin' => $matches[1],
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_resend_replaces_the_previous_otp(): void
    {
        Http::fakeSequence()
            ->push(['message_id' => 'sms-message-1'], 200)
            ->push(['message_id' => 'sms-message-2'], 200);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();
        $oldText = Http::recorded()->first()[0]['sms'];
        preg_match('/(\d{6})$/', $oldText, $oldMatches);

        $this->postJson('/api/auth/resend-otp', ['phone' => '+2347061234567'])->assertAccepted();
        $newText = Http::recorded()->last()[0]['sms'];
        preg_match('/(\d{6})$/', $newText, $newMatches);

        $this->assertNotSame($oldMatches[1], $newMatches[1]);
        $this->postJson('/api/auth/verify-otp', [
            'phone' => '+2347061234567',
            'pin' => $oldMatches[1],
        ])->assertUnprocessable();
        $this->postJson('/api/auth/verify-otp', [
            'phone' => '+2347061234567',
            'pin' => $newMatches[1],
        ])->assertOk();
    }

    public function test_resend_preserves_password_reset_purpose(): void
    {
        User::factory()->create(['phone_number' => '+2347061234567']);
        Http::fakeSequence()
            ->push(['message_id' => 'reset-message-1'], 200)
            ->push(['message_id' => 'reset-message-2'], 200);

        $this->postJson('/api/auth/forgot-password', [
            'phone_number' => '+2347061234567',
        ])->assertAccepted();
        $oldText = Http::recorded()->first()[0]['sms'];
        preg_match('/(\d{6})$/', $oldText, $oldMatches);

        $this->postJson('/api/auth/resend-otp', [
            'phone_number' => '+2347061234567',
        ])->assertAccepted();
        $newText = Http::recorded()->last()[0]['sms'];
        preg_match('/(\d{6})$/', $newText, $newMatches);

        $this->assertNotSame($oldMatches[1], $newMatches[1]);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'purpose' => 'password_reset',
        ]);
    }

    public function test_undelivered_sms_callback_sends_the_same_code_on_whatsapp(): void
    {
        Http::fakeSequence()
            ->push(['message_id' => 'sms-message-callback'], 200)
            ->push(['message_id' => 'wa-message-callback'], 200);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();
        $smsText = Http::recorded()->first()[0]['sms'];

        $this->postJson('/api/webhooks/termii/delivery/test-webhook-token', [
            'message_id' => 'sms-message-callback',
            'status' => 'undelivered',
        ])->assertOk();

        $this->assertSame($smsText, Http::recorded()->last()[0]['sms']);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'whatsapp',
            'delivery_status' => 'sent',
        ]);
    }

    public function test_connection_timeout_falls_back_to_whatsapp_with_the_same_otp(): void
    {
        $smsText = null;
        Http::fake(function (HttpRequest $request) use (&$smsText) {
            if ($request['channel'] === 'dnd') {
                $smsText = $request['sms'];
                throw new ConnectionException('Termii connection timed out.');
            }

            return Http::response(['message_id' => 'wa-timeout-fallback'], 200);
        });

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $whatsAppRequest = Http::recorded()->first()[0];
        preg_match('/(\d{6})$/', $smsText, $matches);
        $this->assertSame($smsText, $whatsAppRequest['sms']);
        $this->assertSame('whatsapp', $whatsAppRequest['channel']);
        $this->assertTrue(Hash::check($matches[1], PhoneVerification::firstOrFail()->otp_hash));
    }
}
