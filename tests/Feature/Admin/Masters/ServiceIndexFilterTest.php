<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Masters;

use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * メニュー一覧の絞り込み（カテゴリ・メニュー名を候補から選ぶ）。
 */
final class ServiceIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_offers_category_and_name_options_and_filters_by_category(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        Service::factory()->create(['name' => '整体60', 'category' => '整体', 'sort_order' => 2]);
        Service::factory()->create(['name' => 'はり30', 'category' => 'はり', 'sort_order' => 1]);
        Service::factory()->create(['name' => '整体30', 'category' => '整体', 'sort_order' => 3]);

        $this->actingAs($admin)->get('/admin/services')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('categoryOptions', ['はり', '整体'])
                ->where('nameOptions', ['はり30', '整体60', '整体30']));

        $this->actingAs($admin)->get('/admin/services?category=整体')->assertOk()
            ->assertInertia(fn ($page) => $page->has('services', 2));
    }
}
