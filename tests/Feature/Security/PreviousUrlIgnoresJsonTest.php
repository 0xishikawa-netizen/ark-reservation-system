<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 全面検証（2026-09-27）で見つけた不具合の再発防止。
 * 画面が fetch() で取る JSON（Accept: application/json、X-Requested-With なし）が「直前の URL」に記録され、
 * Referer の無いリクエストが検証エラーで back() すると生の JSON 画面へ戻されていた。
 */
final class PreviousUrlIgnoresJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_fetch_is_not_stored_as_the_previous_url_for_back_redirects(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin/schedule?date=2026-10-02')->assertOk();
        $this->assertStringEndsWith('/admin/schedule?date=2026-10-02', (string) session()->previousUrl());

        // fetch() と同じ形（Accept: application/json のみ）の GET。
        $this->actingAs($admin)
            ->get('/admin/reports/monthly/data?year=2026&month=10', ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertStringEndsWith('/admin/schedule?date=2026-10-02', (string) session()->previousUrl());

        // Referer の無い画面遷移で検証エラー → 直前の画面（台帳）へ戻る。JSON へは戻らない。
        $this->actingAs($admin)
            ->get('/admin/reports/monthly?month=2026-10')
            ->assertRedirect('/admin/schedule?date=2026-10-02');
    }
}
