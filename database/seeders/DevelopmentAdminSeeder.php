<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 開発用の固定管理者アカウント（Phase 9 / item 10）。
 *
 * - local / development / testing でのみ作成する。それ以外（production 等）では
 *   何もしない。DatabaseSeeder 側でも同じ環境判定でガードしており多重防御。
 * - 冪等: 何度実行しても同じ 1 アカウントへ収束する（firstOrNew + save + syncRoles）。
 * - 正式な spatie `admin` ロールを付与する（admin ロールは RolePermissionSeeder で
 *   全 admin 権限を持つ）。画面側だけ通すような抜け道は作らない。
 * - MFA 要件を満たした状態（two_factor_confirmed_at）にして、EnsureStaffMfa に
 *   ブロックされず /admin へ入れるようにする。two_factor_secret は持たせないため
 *   ブラウザログインは email + password だけで完結する。
 * - `migrate:fresh --seed` でも必ず復元される（DatabaseSeeder から local 系限定で呼ばれる）。
 */
final class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('dev_admin.environments', ['local', 'development', 'testing']);

        if (! app()->environment($allowed)) {
            // production 等では固定認証情報を絶対に作らない。
            return;
        }

        // admin ロール（＋権限）が未整備なら先に整える（単体実行にも耐える）。
        if (Role::query()->where('name', 'admin')->where('guard_name', 'web')->doesntExist()) {
            $this->call(RolePermissionSeeder::class);
        }

        $email = (string) config('dev_admin.email', 'dev-admin@ark.test');

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = (string) config('dev_admin.name', 'ARK 開発管理者');
        // password キャストが hashed のため平文を代入してよい。
        $user->password = (string) config('dev_admin.password', 'password');
        $user->email_verified_at = now();
        $user->two_factor_confirmed_at = now();
        $user->save();

        $user->syncRoles(['admin']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("開発管理者を用意しました: {$email}（admin ロール / 全管理画面アクセス可）");
    }
}
