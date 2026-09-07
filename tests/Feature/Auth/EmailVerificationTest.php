<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_can_view_email_verification_prompt(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/email/verify')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/VerifyEmail'));
    }

    public function test_user_can_verify_email_then_log_in(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verified@example.com',
            'password' => 'password',
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this->actingAs($user)->get($verificationUrl)->assertRedirect('/?verified=1');
        $this->assertTrue($user->fresh()?->hasVerifiedEmail());

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'verified@example.com',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }
}
