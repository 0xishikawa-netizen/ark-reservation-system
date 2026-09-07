<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_response_has_security_headers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
            );
    }

    public function test_authorized_admin_response_denies_framing(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get('/admin')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
