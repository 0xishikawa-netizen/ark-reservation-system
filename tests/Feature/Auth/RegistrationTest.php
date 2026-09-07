<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_registration_creates_customer_role_and_sends_verification_notification(): void
    {
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);

        $response = $this->post('/register', [
            'name' => '予約 太郎',
            'kana' => 'ヨヤク タロウ',
            'email' => 'customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/');

        $user = User::query()->where('email', 'customer@example.com')->firstOrFail();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('staff', 0);
        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'kana' => 'ヨヤク タロウ',
            'created_via' => 'web',
        ]);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasAnyRole(['staff', 'manager', 'admin']));
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
