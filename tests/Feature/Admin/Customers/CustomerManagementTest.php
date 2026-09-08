<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Security\PiiHasher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_can_view_customer_list_and_detail_but_cannot_edit_or_update(): void
    {
        $staff = $this->staffUser('staff');
        $customer = $this->customer('閲覧 顧客', 'エツラン コキャク');

        $this->actingAs($staff)
            ->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Index')
                ->has('customers.data', 1)
                ->where('auth.can.customersView', true)
                ->where('auth.can.customersManage', false));

        $this->actingAs($staff)
            ->get("/admin/customers/{$customer->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Show')
                ->where('customer.user_id', $customer->user_id));

        $this->actingAs($staff)
            ->get("/admin/customers/{$customer->user_id}/edit")
            ->assertForbidden();

        $this->actingAs($staff)
            ->put("/admin/customers/{$customer->user_id}", $this->updatePayload())
            ->assertForbidden();
    }

    public function test_manager_can_view_customer_list_and_detail_but_cannot_edit_or_update(): void
    {
        $manager = $this->staffUser('manager');
        $customer = $this->customer('閲覧 顧客', 'エツラン コキャク');

        $this->actingAs($manager)
            ->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Index')
                ->has('customers.data', 1)
                ->where('auth.can.customersView', true)
                ->where('auth.can.customersManage', false));

        $this->actingAs($manager)
            ->get("/admin/customers/{$customer->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Show')
                ->where('customer.user_id', $customer->user_id));

        $this->actingAs($manager)
            ->get("/admin/customers/{$customer->user_id}/edit")
            ->assertForbidden();

        $this->actingAs($manager)
            ->put("/admin/customers/{$customer->user_id}", $this->updatePayload())
            ->assertForbidden();
    }

    public function test_admin_can_edit_and_update_customer_with_pii_free_audit_summary(): void
    {
        $this->assertAdminCanUpdateCustomer();
    }

    public function test_phone_search_uses_normalized_hmac_for_both_formats(): void
    {
        $admin = $this->staffUser('admin');
        $target = $this->customer(
            '電話 検索対象',
            'デンワ ケンサクタイショウ',
            '090-1234-5678',
        );
        $this->customer('別 顧客', 'ベツ コキャク', '080-9999-9999');

        foreach (['09012345678', '090-1234-5678'] as $query) {
            $this->actingAs($admin)
                ->get('/admin/customers?q='.urlencode($query))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('customers.data', 1)
                    ->where('customers.data.0.user_id', $target->user_id)
                    ->where('filters.q', $query));
        }
    }

    public function test_name_partial_search_returns_matching_customer(): void
    {
        $admin = $this->staffUser('admin');
        $target = $this->customer('山田 花子', 'ヤマダ ハナコ');
        $this->customer('佐藤 太郎', 'サトウ タロウ');

        $this->actingAs($admin)
            ->get('/admin/customers?q='.urlencode('田 花'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.user_id', $target->user_id));
    }

    public function test_updating_phone_recalculates_phone_hmac(): void
    {
        $admin = $this->staffUser('admin');
        $customer = $this->customer('更新 顧客', 'コウシン コキャク', '090-1111-2222');

        $this->actingAs($admin)
            ->put("/admin/customers/{$customer->user_id}", [
                ...$this->updatePayload(),
                'phone' => '080-3333-4444',
            ])
            ->assertSessionHasNoErrors();

        $storedHmac = DB::table('customers')
            ->where('user_id', $customer->user_id)
            ->value('phone_hmac');

        $this->assertSame(PiiHasher::phoneHmac('08033334444'), $storedHmac);
        $this->assertSame('080-3333-4444', $customer->fresh()?->phone);
    }

    private function assertAdminCanUpdateCustomer(): void
    {
        $actor = $this->staffUser('admin');
        $customer = $this->customer(
            '更新前 氏名',
            'コウシンマエ シメイ',
            '090-1234-5678',
        );
        $originalEmail = $customer->user->email;

        $this->actingAs($actor)
            ->get("/admin/customers/{$customer->user_id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Edit')
                ->where('customer.user_id', $customer->user_id));

        $this->actingAs($actor)
            ->put("/admin/customers/{$customer->user_id}", [
                ...$this->updatePayload(),
                'email' => 'changed@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.customers.show', $customer));

        $fresh = $customer->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('更新後 氏名', $fresh->user->name);
        $this->assertSame('コウシンゴ シメイ', $fresh->kana);
        $this->assertSame('080-9876-5432', $fresh->phone);
        $this->assertSame('1992-03-04', $fresh->birthday?->toDateString());
        $this->assertSame('female', $fresh->gender);
        $this->assertSame('管理用メモ更新', $fresh->note);
        $this->assertSame($originalEmail, $fresh->user->email);

        $this->assertDatabaseCount('audit_logs', 1);
        $audit = AuditLog::query()->firstOrFail();

        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame('customer.profile_updated', $audit->action);
        $this->assertSame(Customer::class, $audit->entity_type);
        $this->assertSame((string) $customer->user_id, $audit->entity_id);
        $this->assertStringNotContainsString('email', $audit->summary);

        foreach ([
            '更新後 氏名',
            'コウシンゴ シメイ',
            '080-9876-5432',
            '1992-03-04',
            'female',
            '管理用メモ更新',
            $originalEmail,
        ] as $piiValue) {
            $this->assertStringNotContainsString($piiValue, $audit->summary);
        }
    }

    /** @return array<string, mixed> */
    private function updatePayload(): array
    {
        return [
            'name' => '更新後 氏名',
            'kana' => 'コウシンゴ シメイ',
            'phone' => '080-9876-5432',
            'birthday' => '1992-03-04',
            'gender' => 'female',
            'note' => '管理用メモ更新',
        ];
    }

    private function staffUser(string $role): User
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(
        string $name,
        string $kana,
        ?string $phone = null,
    ): Customer {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('customer');

        return Customer::query()->create([
            'user_id' => $user->id,
            'kana' => $kana,
            'phone' => $phone,
            'created_via' => 'web',
        ]);
    }
}
