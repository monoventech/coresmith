<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_a_reset_link_never_resets_an_account_whose_address_differs_only_by_an_accent(): void
    {
        Notification::fake();
        $jose = User::factory()->create(['email' => 'jose@acme.com']);
        $other = User::factory()->create(['email' => 'jose@acmé.com']);

        $this->post(route('password.email'), ['email' => 'jose@acmé.com']);

        Notification::assertSentTo($other, ResetPassword::class, function (ResetPassword $notification) use ($jose): bool {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => 'jose@acme.com',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])->assertSessionHasErrors('email');

            return Hash::check('password', (string) $jose->fresh()->password);
        });
    }

    public function test_the_reset_email_speaks_like_coresmith_and_links_to_the_reset_page(): void
    {
        $user = User::factory()->create(['email' => 'lena@example.com']);

        $mail = (new ResetPassword('reset-token'))->toMail($user);

        $this->assertSame('Reset your Coresmith password', $mail->subject);
        $this->assertSame('Choose a new password', $mail->actionText);
        $this->assertSame(url(route('password.reset', ['token' => 'reset-token', 'email' => 'lena@example.com'], false)), $mail->actionUrl);
        $this->assertSame(['Someone asked to reset the password for your Coresmith account. The link works for 60 minutes.'], $mail->introLines);
        $this->assertSame(["If it wasn't you, you can ignore this email. Your password stays the same."], $mail->outroLines);
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
