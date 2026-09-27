<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Booth;
use App\Models\Qualification;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Queries\ReservationFormOptionsQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 11-28: メニュー×ブース、メニューの必要資格、スタッフの実施施術・保有資格の設定。
 * 既存の権限（services.manage / staff.manage / settings.manage）に統合し、変更は監査に残す。
 */
final class BookingResourceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_sets_menu_booths_and_required_qualifications_with_audit(): void
    {
        $admin = $this->user('admin');
        $service = Service::factory()->create(['name' => 'パーソナル']);
        [$trainingA, $trainingB] = [Booth::factory()->create(['name' => 'トレーニングA']), Booth::factory()->create(['name' => 'トレーニングB'])];
        $license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();

        $this->actingAs($admin)->put("/admin/services/{$service->id}", $this->servicePayload($service, [
            'booth_ids' => [$trainingA->id, $trainingB->id],
            'qualification_ids' => [$license->id],
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$trainingA->id, $trainingB->id], $service->booths()->pluck('booths.id')->all());
        $this->assertSame([$license->id], $service->qualifications()->pluck('qualifications.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.booths_set']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.qualifications_set']);

        // 同じ内容で保存しても監査は増えない。空にすると全ブース可へ戻る。
        $this->actingAs($admin)->put("/admin/services/{$service->id}", $this->servicePayload($service, [
            'booth_ids' => [$trainingA->id, $trainingB->id], 'qualification_ids' => [$license->id],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'service.booths_set')->count());
        $this->actingAs($admin)->put("/admin/services/{$service->id}", $this->servicePayload($service, [
            'booth_ids' => [], 'qualification_ids' => [],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(0, $service->booths()->count());

        $this->actingAs($admin)->put("/admin/services/{$service->id}", $this->servicePayload($service, ['booth_ids' => [999999]]))
            ->assertSessionHasErrors('booth_ids.0');
    }

    public function test_staff_capabilities_and_qualifications_are_set_from_the_staff_screen(): void
    {
        $admin = $this->user('admin');
        $staff = Staff::factory()->create(['display_name' => '担当B']);
        $service = Service::factory()->create(['name' => 'はり']);
        $license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put("/admin/staff/{$staff->user_id}", [
                'display_name' => '担当B', 'color' => '#123456', 'is_bookable' => true, 'sort_order' => 0,
                'role' => $staff->user->getRoleNames()->first() ?? 'staff', 'is_active' => true,
                'service_ids' => [$service->id], 'qualification_ids' => [$license->id],
            ])->assertSessionHasNoErrors();

        $this->assertSame([$service->id], $staff->services()->pluck('services.id')->all());
        $this->assertSame([$license->id], $staff->qualifications()->pluck('qualifications.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.services_set']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.qualifications_set']);
    }

    public function test_only_permitted_roles_can_change_resource_settings(): void
    {
        $service = Service::factory()->create();
        $staff = Staff::factory()->create();
        foreach (['staff', 'manager'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->put("/admin/services/{$service->id}", $this->servicePayload($service, ['booth_ids' => []]))->assertForbidden();
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->put("/admin/staff/{$staff->user_id}", ['display_name' => 'x', 'qualification_ids' => []])->assertForbidden();
        }
        // 資格マスタは既存の業務マスタ権限（settings.manage）。一般スタッフは変更できない。
        $this->actingAs($this->user('staff'))
            ->post('/admin/settings/business-masters/karte/qualifications', ['code' => 'x', 'name' => 'x', 'is_active' => true, 'sort_order' => 1])
            ->assertForbidden();
        $this->assertDatabaseMissing('qualifications', ['code' => 'x']);
    }

    public function test_qualification_master_is_managed_in_business_masters(): void
    {
        $this->actingAs($this->user('admin'))
            ->post('/admin/settings/business-masters/karte/qualifications', ['code' => 'moxibustion', 'name' => 'きゅう師', 'is_active' => true, 'sort_order' => 20])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('qualifications', ['code' => 'moxibustion', 'name' => 'きゅう師']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'karte_master.created']);
    }

    public function test_reservation_panel_options_offer_only_qualified_staff_and_mapped_booths(): void
    {
        $service = Service::factory()->create(['is_active' => true]);
        [$qualified, $unqualified] = [Staff::factory()->create(), Staff::factory()->create()];
        $service->staff()->attach([$qualified->user_id, $unqualified->user_id]);
        $license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();
        $service->qualifications()->attach($license->id);
        $qualified->qualifications()->attach($license->id);
        $bed = Booth::factory()->create(['is_active' => true]);
        Booth::factory()->create(['is_active' => true]);
        $service->booths()->attach($bed->id);

        $option = collect(app(ReservationFormOptionsQuery::class)->get()['services'])->firstWhere('id', $service->id);
        $this->assertSame([$qualified->user_id], $option['staff_ids']);
        $this->assertSame([$bed->id], $option['booth_ids']);
    }

    /** @param array<string, mixed> $overrides */
    private function servicePayload(Service $service, array $overrides): array
    {
        return [
            'name' => $service->name, 'duration_min' => 60, 'price' => 8800, 'category' => null, 'color' => '#abcdef',
            'is_online_bookable' => false, 'requires_staff' => false, 'sort_order' => 0, 'staff_ids' => [],
            ...$overrides,
        ];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
