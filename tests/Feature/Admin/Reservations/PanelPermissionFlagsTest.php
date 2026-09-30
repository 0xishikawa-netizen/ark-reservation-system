<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 全面検証（2026-09-30）：予約パネルは、画面が操作ボタンを出し分けられるよう権限を返す。
 * 一般スタッフ（閲覧のみ）には予約管理・顧客メモ編集の権限が無いことを返し、書き込みはサーバーでも拒否する。
 */
final class PanelPermissionFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_flags_match_role_permissions_and_writes_are_refused_for_staff(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $reservation = Reservation::factory()->create();
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($staff)->getJson("/admin/reservations/{$reservation->id}/panel")->assertOk()
            ->assertJsonPath('can.manage', false)
            ->assertJsonPath('can.view_customer', true)
            ->assertJsonPath('can.edit_customer', false)
            ->assertJsonPath('reservation.visit_entry_url', null);
        $this->actingAs($staff)->patchJson("/admin/customers/{$reservation->customer_id}/note", ['note' => 'スタッフ'])->assertForbidden();
        $this->actingAs($staff)->getJson("/admin/customers/{$reservation->customer_id}/board-panel")->assertOk()
            ->assertJsonPath('can.edit_customer', false);

        $this->actingAs($admin)->getJson("/admin/reservations/{$reservation->id}/panel")->assertOk()
            ->assertJsonPath('can.manage', true)
            ->assertJsonPath('can.edit_customer', true);
    }
}
