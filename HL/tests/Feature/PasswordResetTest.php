<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_existing_email_receives_reset_code_with_generic_response(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $response = $this->postJson('/api/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for that email, a password reset code has been sent.',
            ]);
        Notification::assertSentToOnce($user, PasswordResetCode::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        Notification::assertSentTo($user, function (PasswordResetCode $notification) use ($user): bool {
            $storedToken = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

            return is_string($storedToken) && Hash::check($notification->code, $storedToken);
        });
    }

    public function test_unknown_email_gets_same_response_without_notification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/password/forgot', [
            'email' => 'missing@gmail.com',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for that email, a password reset code has been sent.',
            ]);
        Notification::assertNothingSent();
    }

    public function test_missing_smtp_settings_report_email_unavailable_without_creating_a_code(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);

        $this->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertServiceUnavailable()
            ->assertJson([
                'message' => 'We could not send a reset code to that email right now. Please try again later.',
            ]);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::assertNothingSent();
    }

    public function test_reset_code_email_links_to_the_frontend_reset_page(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        config(['app.frontend_url' => 'http://localhost:4200']);

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, function (PasswordResetCode $notification, array $channels) use ($user): bool {
            $mail = $notification->toMail($user);

            return in_array('mail', $channels, true)
                && preg_match('/^\d{6}$/', $notification->code) === 1
                && $mail->actionUrl === 'http://localhost:4200/reset-password';
        });
    }

    public function test_reset_code_cannot_be_resent_within_one_minute(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        Notification::assertSentToOnce($user, PasswordResetCode::class);
    }

    public function test_valid_code_changes_password_and_revokes_existing_api_tokens(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $user->createToken('existing-session');
        $code = '483921';
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($code),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertOk()
            ->assertJson(['message' => 'Password reset successfully.']);
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_code_can_only_be_used_once(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $code = '483921';
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($code),
            'created_at' => now(),
        ]);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertUnprocessable();
    }

    public function test_expired_code_does_not_change_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('483921'),
            'created_at' => now()->subMinutes(11),
        ]);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'code' => '483921',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_invalid_code_does_not_change_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('483921'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJson(['message' => 'This verification code is invalid or expired.']);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_code_verification_is_rate_limited_per_email(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('483921'),
            'created_at' => now(),
        ]);
        RateLimiter::clear('password-reset-code:'.hash('sha256', $user->email));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/password/reset', [
                'email' => $user->email,
                'code' => '000000',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertTooManyRequests();
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_reset_request_requires_a_valid_email(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_request_rejects_non_gmail_addresses(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'person@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_reset_rejects_non_gmail_addresses(): void
    {
        $this->postJson('/api/password/reset', [
            'email' => 'person@example.com',
            'code' => '123456',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
