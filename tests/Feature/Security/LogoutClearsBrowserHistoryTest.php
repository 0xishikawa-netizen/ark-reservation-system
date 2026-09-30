<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 全面検証（2026-09-30）で見つけた不具合の再発防止。
 * ログアウト後にブラウザの「戻る」で、Inertia がブラウザ履歴に保存したページ（顧客名・予約）が
 * サーバーへ問い合わせずに再表示されていた。履歴を暗号化し、ログアウト時に消去させる。
 */
final class LogoutClearsBrowserHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_encrypt_history_and_logout_clears_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $page = $this->actingAs($admin)->get('/admin/schedule')->assertOk()->viewData('page');
        $this->assertTrue($page['encryptHistory'], '管理画面のページデータは暗号化して履歴に保存する');
        $this->assertFalse($page['clearHistory']);

        $this->actingAs($admin)->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        // ログアウト直後の画面で、ブラウザに履歴の消去（暗号鍵の破棄）を指示する。
        $afterLogout = $this->get('/')->assertOk()->viewData('page');
        $this->assertTrue($afterLogout['clearHistory']);

        // 以後の画面では消去指示は1回限り。
        $this->assertFalse($this->get('/')->viewData('page')['clearHistory']);
        // 未ログインで管理画面を開くとログインへ。
        $this->get('/admin/schedule')->assertRedirect('/login');
    }
}
