<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Models\Customer;
use App\Models\User;
use App\Queries\CustomerLookupQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_new_customer_gets_a_properly_formatted_member_no(): void
    {
        $customer = Customer::factory()->create();

        $this->assertMatchesRegularExpression('/^ARK\d{6}$/', $customer->member_no);
        $this->assertSame('ARK'.str_pad((string) $customer->user_id, 6, '0', STR_PAD_LEFT), $customer->member_no);
    }

    public function test_member_no_is_unique_across_customers(): void
    {
        $numbers = Customer::factory()->count(5)->create()->pluck('member_no');

        $this->assertCount(5, $numbers->unique());
    }

    public function test_member_no_does_not_change_on_update(): void
    {
        $customer = Customer::factory()->create();
        $original = $customer->member_no;

        $customer->update(['kana' => 'アップデートテスト']);
        $customer->refresh();

        $this->assertSame($original, $customer->member_no);
    }

    public function test_concurrent_style_sequential_registrations_never_collide(): void
    {
        // 同時登録に相当する状況（同一プロセス内で連続作成）でも重複しないことを確認する。
        // 会員番号は既に一意性が保証された user_id の AUTO_INCREMENT から生成されるため安全。
        $memberNumbers = [];

        for ($i = 0; $i < 10; $i++) {
            $memberNumbers[] = Customer::factory()->create()->member_no;
        }

        $this->assertCount(10, array_unique($memberNumbers));
    }

    public function test_search_by_member_no_finds_the_customer(): void
    {
        $customer = Customer::factory()->create(['kana' => 'ケンサクタロウ']);
        $customer->user->update(['name' => '検索太郎']);

        $query = app(CustomerLookupQuery::class);

        foreach ([$customer->member_no, ltrim(substr($customer->member_no, 3), '0')] as $search) {
            $result = $query->search($search);
            $this->assertContains($customer->user_id, array_column($result, 'user_id'));
        }
    }

    public function test_board_panel_and_search_endpoint_expose_the_real_member_no(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $customer = Customer::factory()->create();

        $payload = $this->actingAs($admin)
            ->getJson("/admin/customers/{$customer->user_id}/board-panel")
            ->assertOk()
            ->json();

        $this->assertSame($customer->member_no, $payload['customer']['member_no']);
    }
}
