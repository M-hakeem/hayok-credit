<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PhoneOtpTest extends TestCase
{
    private const OTP_EMAIL = 'borrower@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'services.termii.api_key' => 'test-api-key',
            'services.termii.sender_id' => 'HayokCredit',
            'services.termii.type' => 'plain',
            'services.termii.channel' => 'generic',
            'services.termii.url' => 'https://api.ng.termii.com/api/sms/send',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'live.smtp.mailtrap.io',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'apismtp@mailtrap.io',
            'mail.mailers.smtp.password' => 'test-smtp-password',
            'mail.from.address' => 'support@example.com',
            'mail.from.name' => 'Hayok Credit',
        ]);
    }

    public function test_it_sends_otp_using_termii_and_verifies_it(): void
    {
        $this->fakeProviders();

        $response = $this->postJson('/api/auth/send-otp', [
            'phone' => '+234 706-123-4567',
            'email' => self::OTP_EMAIL,
        ]);
        $request = $this->termiiRequests()->first()[0];
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
        $this->assertOtpEmailSent($matches[1]);
        $this->assertCount(1, Http::recorded());
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
        $this->fakeProviders(termiiAccepted: false);

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => self::OTP_EMAIL,
        ])->assertAccepted();

        $this->assertCount(1, $this->termiiRequests());
        preg_match('/(\d{6})$/', $this->termiiRequests()->first()[0]['sms'], $matches);
        $this->assertOtpEmailSent($matches[1]);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'sms',
            'delivery_status' => 'failed',
        ]);
    }

    public function test_send_otp_requires_a_valid_email_address(): void
    {
        Http::fake();

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertCount(0, Http::recorded());
        Mail::assertNothingOutgoing();
    }

    public function test_termii_api_key_is_sent_in_the_request_body(): void
    {
        $this->fakeProviders();

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => self::OTP_EMAIL,
        ])->assertAccepted();

        $request = $this->termiiRequests()->first()[0];
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
        $this->fakeProviders();

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => self::OTP_EMAIL,
        ])->assertAccepted();

        $this->assertSame(
            'https://api.ng.termii.com/api/sms/send',
            $this->termiiRequests()->first()[0]->url()
        );
    }

    public function test_missing_termii_credentials_does_not_make_a_request(): void
    {
        config([
            'services.termii.api_key' => null,
            'services.termii.sender_id' => null,
        ]);
        $this->fakeProviders();

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => self::OTP_EMAIL,
        ])->assertAccepted();

        $this->assertCount(0, $this->termiiRequests());
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_status' => 'failed',
        ]);
    }

    public function test_resend_replaces_the_previous_otp(): void
    {
        $this->fakeProviders();

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => self::OTP_EMAIL,
        ])->assertAccepted();
        $oldText = $this->termiiRequests()->first()[0]['sms'];
        preg_match('/(\d{6})$/', $oldText, $oldMatches);

        $this->postJson('/api/auth/resend-otp', ['phone' => '+2347061234567'])->assertAccepted();
        $newText = $this->termiiRequests()->last()[0]['sms'];
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
        $this->fakeProviders();

        $this->postJson('/api/auth/forgot-password', [
            'phone_number' => '+2347061234567',
        ])->assertAccepted();
        $oldText = $this->termiiRequests()->first()[0]['sms'];
        preg_match('/(\d{6})$/', $oldText, $oldMatches);

        $this->postJson('/api/auth/resend-otp', [
            'phone_number' => '+2347061234567',
        ])->assertAccepted();
        $newText = $this->termiiRequests()->last()[0]['sms'];
        preg_match('/(\d{6})$/', $newText, $newMatches);

        $this->assertNotSame($oldMatches[1], $newMatches[1]);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'purpose' => 'password_reset',
        ]);
    }

    public function test_connection_failure_marks_otp_delivery_failed(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.ng.termii.com')) {
                throw new ConnectionException('Termii connection timed out.');
            }

            return Http::response(['success' => true], 200);
        });

        $this->postJson('/api/auth/send-otp', [
            'phone' => '+2347061234567',
            'email' => self::OTP_EMAIL,
        ])->assertAccepted();

        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo(self::OTP_EMAIL)
            && preg_match('/^\d{6}$/', $mail->otp) === 1);
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'delivery_channel' => 'sms',
            'delivery_status' => 'failed',
        ]);
    }

    private function fakeProviders(bool $termiiAccepted = true): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => $termiiAccepted ? 'ok' : '401',
                'message' => $termiiAccepted ? 'Successfully Sent' : 'Invalid credentials',
            ], 200),
        ]);
    }

    private function termiiRequests()
    {
        return Http::recorded()->filter(
            fn (array $recorded) => str_contains($recorded[0]->url(), 'api.ng.termii.com')
        )->values();
    }

    private function assertOtpEmailSent(string $otp): void
    {
        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo(self::OTP_EMAIL)
            && $mail->otp === $otp);
    }
}
