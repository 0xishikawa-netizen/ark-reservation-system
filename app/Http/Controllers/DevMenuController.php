<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * お試し環境の開発用メニュー（Task 11-36）。
 *
 * local / staging かつ STAGING_DEV_MENU=true のときだけ表示し、それ以外は 404。
 * パスワードは画面に出さない（関係者へ別途共有する）。
 */
final class DevMenuController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(self::enabled(), 404);

        return Inertia::render('DevMenu', [
            'appName' => config('app.name'),
            'environment' => app()->environment(),
            'quickLogins' => array_keys(self::quickLoginAccounts()),
        ]);
    }

    /**
     * 開発用メニューの「〇〇としてログイン」で入力済みにするアカウント（Task 11-37）。
     *
     * @return array<string, string> キー => メールアドレス
     */
    public static function quickLoginAccounts(): array
    {
        return [
            'admin' => (string) config('dev_admin.email'),
            'staff' => 'staff.shigeki@demo.ark',
            'customerA' => 'cust01@demo.ark',
            'customerB' => 'cust02@demo.ark',
            'customerC' => 'cust03@demo.ark',
        ];
    }

    /**
     * ログイン画面に入れておく値。お試し環境かつキーが正しいときだけ返す。
     * パスワードはデモ用の共通パスワード（Basic 認証の内側でのみ表示される）。
     *
     * @return array{email: string, password: string}|null
     */
    public static function prefillFor(?string $key): ?array
    {
        $accounts = self::quickLoginAccounts();

        if (! self::enabled() || $key === null || ! isset($accounts[$key])) {
            return null;
        }

        $password = app()->environment('staging')
            ? (string) config('staging.demo_password', '')
            : (string) config('dev_admin.password', 'password');

        return ['email' => $accounts[$key], 'password' => $password];
    }

    /**
     * テスト用アカウント（パスワードは出さない）。ログイン画面の案内でも使う。
     *
     * @return array{admin: string, staff: string, customer: string}
     */
    public static function accounts(): array
    {
        return [
            'admin' => (string) config('dev_admin.email'),
            'staff' => 'staff.shigeki@demo.ark',
            'customer' => 'cust01@demo.ark',
        ];
    }

    public static function enabled(): bool
    {
        return app()->environment(['local', 'staging']) && (bool) config('staging.dev_menu');
    }
}
