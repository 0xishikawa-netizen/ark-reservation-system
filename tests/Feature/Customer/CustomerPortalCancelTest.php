<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Membership;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * customer の解約操作が自分の membership にのみ作用する（Stripe gateway を呼ぶため DatabaseMigrations）。
 */
final class CustomerPortalCancelTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_customer_cancel_only_affects_own_membership(): void
    {
        $me = Customer::factory()->create();
        $me->user->assignRole('customer');
        $other = Customer::factory()->create();
        $other->user->assignRole('customer');

        $mine = Membership::factory()->create([
            'customer_id' => $me->user_id, 'status' => 'active', 'stripe_subscription_id' => 'sub_mine',
        ]);
        $theirs = Membership::factory()->create([
            'customer_id' => $other->user_id, 'status' => 'active', 'stripe_subscription_id' => 'sub_theirs',
        ]);

        $this->actingAs($me->user)
            ->post('/mypage/membership/cancel')
            ->assertRedirect();

        $this->assertTrue($mine->fresh()->cancel_at_period_end);
        $this->assertSame('canceling', $mine->fresh()->status->value);
        $this->assertFalse($theirs->fresh()->cancel_at_period_end, '他人の membership は不変');
        $this->assertSame('active', $theirs->fresh()->status->value);
    }
}
