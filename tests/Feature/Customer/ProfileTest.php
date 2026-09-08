<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Security\PiiHasher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_verified_customer_can_view_and_update_own_profile_with_audit_actor(): void
    {
        $customer = $this->customer('顧客 A', 'コキャク エー', '090-1111-2222');
        $user = $customer->user;

        $this->actingAs($user)
            ->get('/mypage/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Profile/Show')
                ->where('customer.user_id', $customer->user_id)
                ->where('customer.kana', 'コキャク エー'));

        $this->actingAs($user)
            ->put('/mypage/profile', [
                'name' => '顧客 A 更新',
                'kana' => 'コキャク エー コウシン',
                'phone' => '080-3333-4444',
                'birthday' => '1995-06-07',
                'gender' => 'other',
                'note' => '顧客が変更してはいけないメモ',
                'email' => 'changed@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.profile.show'));

        $fresh = $customer->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('顧客 A 更新', $fresh->user->name);
        $this->assertSame('コキャク エー コウシン', $fresh->kana);
        $this->assertSame('080-3333-4444', $fresh->phone);
        $this->assertSame('1995-06-07', $fresh->birthday?->toDateString());
        $this->assertSame('other', $fresh->gender);
        $this->assertSame('既存の管理用メモ', $fresh->note);
        $this->assertNotSame('changed@example.com', $fresh->user->email);
        $this->assertSame(
            PiiHasher::phoneHmac('08033334444'),
            DB::table('customers')->where('user_id', $customer->user_id)->value('phone_hmac'),
        );

        $this->assertDatabaseCount('audit_logs', 1);
        $audit = AuditLog::query()->firstOrFail();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('customer.profile_updated', $audit->action);
        $this->assertSame((string) $customer->user_id, $audit->entity_id);
        $this->assertStringNotContainsString('080-3333-4444', $audit->summary);
        $this->assertStringNotContainsString('changed@example.com', $audit->summary);
    }

    public function test_staff_without_customer_record_receives_forbidden_response(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get('/mypage/profile')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/mypage/profile')
            ->assertRedirect(route('login'));
    }

    public function test_unverified_customer_is_redirected_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();
        $user->assignRole('customer');
        Customer::query()->create([
            'user_id' => $user->id,
            'kana' => 'ミニンショウ コキャク',
        ]);

        $this->actingAs($user)
            ->get('/mypage/profile')
            ->assertRedirect(route('verification.notice'));
    }

    private function customer(string $name, string $kana, string $phone): Customer
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('customer');

        return Customer::query()->create([
            'user_id' => $user->id,
            'kana' => $kana,
            'phone' => $phone,
            'note' => '既存の管理用メモ',
            'created_via' => 'web',
        ]);
    }
}
