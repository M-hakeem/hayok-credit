<?php

namespace Tests\Feature;

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneOtpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bulksmslive.email' => 'otp@example.test',
            'services.bulksmslive.password' => 'test-password',
            'services.bulksmslive.api_key' => null,
            'services.bulksmslive.sender_name' => 'HayokCredit',
            'services.bulksmslive.force_dnd' => 1,
            'services.bulksmslive.url' => 'https://api.bulksmslive.com/v2/app/sms',
            'services.bulksmslive.api_key_url' => 'https://api.bulksmslive.com/v2/app/sendsms',
        ]);
    }

    public function test_it_sends_otp_using_bulksmslive_and_verifies_it(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'Queued'], 200)]);

        $response = $this->postJson('/api/auth/send-otp', ['phone' => '+234 706-123-4567']);
        $request = Http::recorded()->first()[0];
        $message = $request['message'];
        preg_match('/(\d{6})$/', $message, $matches);

        $response->assertAccepted()
            ->assertJsonMissingPath('data')
            ->assertDontSee($matches[1]);

        $this->assertSame('https://api.bulksmslive.com/v2/app/sms', $request->url());
        $this->assertSame('otp@example.test', $request['email']);
        $this->assertSame('test-password', $request['password']);
        $this->assertSame('HayokCredit', $request['sender_name']);
        $this->assertSame('2347061234567', $request['recipients']);
        $this->assertSame(1, $request['forcednd']);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'verified' => false,
            'delivery_channel' => 'sms',
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

    public function test_provider_rejection_marks_otp_delivery_failed(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Invalid credentials'], 200)]);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertCount(1, Http::recorded());
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'sms',
            'delivery_status' => 'failed',
        ]);
    }

    public function test_it_uses_bulk_sms_live_api_key_authentication_when_configured(): void
    {
        config(['services.bulksmslive.api_key' => 'test-api-key']);
        Http::fake(['*' => Http::response(['status' => 1, 'msg' => 'Message sent successful'], 200)]);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $request = Http::recorded()->first()[0];
        $this->assertSame('https://api.bulksmslive.com/v2/app/sendsms', $request->url());
        $this->assertSame('test-api-key', $request->header('Authorization')[0]);
        $this->assertSame('application/json', $request->header('Accept')[0]);
        $this->assertArrayNotHasKey('email', $request->data());
        $this->assertArrayNotHasKey('password', $request->data());
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_status' => 'sent',
        ]);
    }

    public function test_api_key_auth_uses_default_endpoint_when_api_key_url_is_missing(): void
    {
        config([
            'services.bulksmslive.api_key' => 'test-api-key',
            'services.bulksmslive.api_key_url' => null,
        ]);
        Http::fake(['*' => Http::response(['status' => 1, 'msg' => 'Message sent successful'], 200)]);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertSame(
            'https://api.bulksmslive.com/v2/app/sendsms',
            Http::recorded()->first()[0]->url()
        );
    }

    public function test_resend_replaces_the_previous_otp(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'success'], 200)
            ->push(['status' => 'success'], 200);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();
        $oldText = Http::recorded()->first()[0]['message'];
        preg_match('/(\d{6})$/', $oldText, $oldMatches);

        $this->postJson('/api/auth/resend-otp', ['phone' => '+2347061234567'])->assertAccepted();
        $newText = Http::recorded()->last()[0]['message'];
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
            ->push(['status' => 'success'], 200)
            ->push(['status' => 'success'], 200);

        $this->postJson('/api/auth/forgot-password', [
            'phone_number' => '+2347061234567',
        ])->assertAccepted();
        $oldText = Http::recorded()->first()[0]['message'];
        preg_match('/(\d{6})$/', $oldText, $oldMatches);

        $this->postJson('/api/auth/resend-otp', [
            'phone_number' => '+2347061234567',
        ])->assertAccepted();
        $newText = Http::recorded()->last()[0]['message'];
        preg_match('/(\d{6})$/', $newText, $newMatches);

        $this->assertNotSame($oldMatches[1], $newMatches[1]);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'purpose' => 'password_reset',
        ]);
    }

    public function test_connection_failure_marks_otp_delivery_failed(): void
    {
        Http::fake(function () {
            throw new ConnectionException('BulkSMSLive connection timed out.');
        });

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'sms',
            'delivery_status' => 'failed',
        ]);
    }
}
