<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_and_complete_a_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'reset@example.com',
        ]);

        $this->post('/forgot-password', [
            'email' => $user->email,
        ])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );
        $this->assertNotNull($token);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }
}
