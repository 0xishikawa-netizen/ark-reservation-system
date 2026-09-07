<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_audited_with_actor_and_email(): void
    {
        $user = User::factory()->create([
            'email' => 'audit-login@example.com',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->getKey(),
            'action' => 'auth.login',
            'summary' => 'ログイン: audit-login@example.com',
        ]);
    }

    public function test_failed_login_is_audited_without_actor(): void
    {
        $user = User::factory()->create([
            'email' => 'audit-failed@example.com',
            'password' => 'correct-password',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => null,
            'action' => 'auth.failed',
            'summary' => 'ログイン失敗: audit-failed@example.com',
        ]);
    }
}
