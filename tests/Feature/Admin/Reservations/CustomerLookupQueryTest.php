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

    public function test_member_number_search_finds_customer_by_real_member_no(): void
    {
        $customer = Customer::factory()->create(['kana' => 'サトウ ジロウ']);
        $customer->user->forceFill(['name' => '佐藤 次郎'])->save();

        $query = app(CustomerLookupQuery::class);
        $result = $query->search($customer->member_no);

        $this->assertCount(1, $result);
        $this->assertSame($customer->user_id, $result[0]['user_id']);
        $this->assertSame($customer->member_no, $result[0]['member_no']);

        // 先頭ゼロなしの数字だけでも同じ顧客に一致する。
        $resultWithoutLeadingZeros = $query->search((string) $customer->user_id);
        $this->assertContains(
            $customer->user_id,
            array_column($resultWithoutLeadingZeros, 'user_id'),
        );
    }

    public function test_staff_with_view_permission_can_use_customer_search_endpoint(): void
    {
        // 検索は更新を伴わない閲覧操作なので、reservations.view を持つ staff ロールでも使える
        // （台帳の予約カードから同じ顧客情報を見られるのと同じ扱いに統一。§19）。
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->getJson('/admin/reservations/customer-search?q=test')
            ->assertOk();
    }

    public function test_customer_without_any_reservation_permission_cannot_use_customer_search_endpoint(): void
    {
        $customer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $customer->assignRole('customer');

        $this->actingAs($customer)
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
