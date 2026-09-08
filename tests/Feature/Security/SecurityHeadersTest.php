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

    /**
     * Phase 5 で Stripe Payment Element 用に script/frame/connect/img を最小限だけ拡張した。
     * 拡張したのは顧客側のみで、frame-ancestors / object-src / base-uri / form-action は不変。
     */
    public function test_web_response_has_security_headers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' https://js.stripe.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.stripe.com; font-src 'self' data:; connect-src 'self' https://api.stripe.com https://js.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
            );
    }

    public function test_admin_response_is_not_widened_for_stripe(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get('/admin')
            ->assertOk()
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
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
