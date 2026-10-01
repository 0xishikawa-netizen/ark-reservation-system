<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 電話予約などで未登録のお客様の予約を台帳から取るための「仮登録」。
 * 電話口では氏名が聞き取れないことがあるため、分かった項目だけで作れることを守る。
 */
final class ProvisionalCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_can_create_provisional_customer_with_name_only(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson('/admin/reservations/provisional-customer', ['name' => '山田 太郎'])
            ->assertOk()
            ->assertJsonPath('name', '山田 太郎');

        $userId = (int) $response->json('user_id');
        $customer = Customer::query()->findOrFail($userId);

        $this->assertSame('admin', $customer->created_via);
        $this->assertSame('ARK'.str_pad((string) $userId, 6, '0', STR_PAD_LEFT), $customer->member_no);
        // ログインさせないため、パスワードは持たせない。
        $this->assertNull($customer->user->password);
    }

    public function test_phone_only_customer_gets_a_traceable_display_name(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson('/admin/reservations/provisional-customer', ['phone' => '09098765432'])
            ->assertOk();

        $this->assertSame('未確認（09098765432）', $response->json('name'));
        $this->assertSame('09098765432', Customer::query()->findOrFail((int) $response->json('user_id'))->phone);
    }

    public function test_kana_only_customer_uses_kana_as_display_name(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/admin/reservations/provisional-customer', ['kana' => 'ヤマダ タロウ'])
            ->assertOk()
            ->assertJsonPath('name', 'ヤマダ タロウ')
            ->assertJsonPath('kana', 'ヤマダ タロウ');
    }

    public function test_name_kana_phone_and_gender_are_saved_and_invalid_gender_is_rejected(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson('/admin/reservations/provisional-customer', [
                'name' => '山田 花子', 'kana' => 'ヤマダ ハナコ', 'phone' => '09011112222', 'gender' => 'female',
            ])->assertOk();

        $customer = Customer::query()->findOrFail((int) $response->json('user_id'));
        $this->assertSame('female', $customer->gender);
        $this->assertSame('ヤマダ ハナコ', $customer->kana);

        $this->actingAs($this->manager())
            ->postJson('/admin/reservations/provisional-customer', ['name' => '山田', 'gender' => 'robot'])
            ->assertUnprocessable()->assertJsonValidationErrors('gender');
    }

    public function test_all_blank_input_is_rejected(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/admin/reservations/provisional-customer', ['name' => ' ', 'kana' => '', 'phone' => ''])
            ->assertStatus(422);
    }

    public function test_provisional_customer_is_searchable_right_after_creation(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->postJson('/admin/reservations/provisional-customer', ['name' => '電話 花子'])
            ->assertOk();

        $results = $this->actingAs($manager)
            ->getJson('/admin/reservations/customer-search?q=電話')
            ->assertOk()
            ->json();

        $this->assertSame('電話 花子', $results[0]['name']);
    }

    public function test_staff_without_manage_permission_cannot_create_provisional_customer(): void
    {
        $customer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->postJson('/admin/reservations/provisional-customer', ['name' => '山田 太郎'])
            ->assertForbidden();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('manager');

        return $user;
    }
}
