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
            'services.termii.api_key' => 'test-api-key',
            'services.termii.sender_id' => 'HayokCredit',
            'services.termii.type' => 'plain',
            'services.termii.channel' => 'generic',
            'services.termii.url' => 'https://api.ng.termii.com/api/sms/send',
        ]);
    }

    public function test_it_sends_otp_using_termii_and_verifies_it(): void
    {
        Http::fake(['*' => Http::response(['code' => 'ok', 'message' => 'Successfully Sent'], 200)]);

        $response = $this->postJson('/api/auth/send-otp', ['phone' => '+234 706-123-4567']);
        $request = Http::recorded()->first()[0];
        $message = $request['sms'];
        preg_match('/(\d{6})$/', $message, $matches);

        $response->assertAccepted()
            ->assertJsonMissingPath('data')
            ->assertDontSee($matches[1]);

        $this->assertSame('https://api.ng.termii.com/api/sms/send', $request->url());
        $this->assertSame('test-api-key', $request['api_key']);
        $this->assertSame('HayokCredit', $request['from']);
        $this->assertSame('2347061234567', $request['to']);
        $this->assertSame('plain', $request['type']);
        $this->assertSame('generic', $request['channel']);
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
        Http::fake(['*' => Http::response(['code' => '401', 'message' => 'Invalid credentials'], 200)]);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertCount(1, Http::recorded());
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'sms',
            'delivery_status' => 'failed',
        ]);
    }

    public function test_termii_api_key_is_sent_in_the_request_body(): void
    {
        Http::fake(['*' => Http::response(['code' => 'ok', 'message' => 'Successfully Sent'], 200)]);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $request = Http::recorded()->first()[0];
        $this->assertSame('test-api-key', $request['api_key']);
        $this->assertArrayNotHasKey('email', $request->data());
        $this->assertArrayNotHasKey('password', $request->data());
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_status' => 'sent',
        ]);
    }

    public function test_termii_default_endpoint_is_used_when_url_is_missing(): void
    {
        config(['services.termii.url' => null]);
        Http::fake(['*' => Http::response(['code' => 'ok'], 200)]);

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertSame(
            'https://api.ng.termii.com/api/sms/send',
            Http::recorded()->first()[0]->url()
        );
    }

    public function test_missing_termii_credentials_does_not_make_a_request(): void
    {
        config([
            'services.termii.api_key' => null,
            'services.termii.sender_id' => null,
        ]);
        Http::fake();

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertCount(0, Http::recorded());
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_status' => 'failed',
        ]);
    }

    public function test_resend_replaces_the_previous_otp(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'success'], 200)
            ->push(['status' => 'success'], 200);

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
            ->push(['status' => 'success'], 200)
            ->push(['status' => 'success'], 200);

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

    public function test_connection_failure_marks_otp_delivery_failed(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Termii connection timed out.');
        });

        $this->postJson('/api/auth/send-otp', ['phone' => '+2347061234567'])->assertAccepted();

        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'sms',
            'delivery_status' => 'failed',
        ]);
    }
}
