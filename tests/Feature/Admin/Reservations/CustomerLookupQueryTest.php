<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Models\Customer;
use App\Models\User;
use App\Queries\CustomerLookupQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerLookupQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_name_kana_and_phone_hmac_search_find_customer_without_returning_phone(): void
    {
        $customer = Customer::factory()->create([
            'kana' => 'ヤマダ タロウ',
            'phone' => '090-1234-5678',
        ]);
        $customer->user->forceFill(['name' => '山田 太郎'])->save();
        $query = app(CustomerLookupQuery::class);

        foreach (['山田', 'ヤマダ', '09012345678'] as $search) {
            $result = $query->search($search);

            $this->assertCount(1, $result);
            $this->assertSame($customer->user_id, $result[0]['user_id']);
            $this->assertArrayNotHasKey('phone', $result[0]);
        }
    }

    public function test_staff_without_manage_permission_cannot_use_customer_search_endpoint(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->getJson('/admin/reservations/customer-search?q=test')
            ->assertForbidden();
    }

    public function test_customer_lookup_is_limited_to_twenty_results(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $customer = Customer::factory()->create(['kana' => "テスト {$index}"]);
            $customer->user->forceFill(['name' => "検索対象 {$index}"])->save();
        }

        $this->assertCount(20, app(CustomerLookupQuery::class)->search('検索対象'));
    }
}
