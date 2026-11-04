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
            'accounts' => [
                'admin' => (string) config('dev_admin.email'),
                'staff' => 'staff.shigeki@demo.ark',
                'customer' => 'cust01@demo.ark',
            ],
        ]);
    }

    public static function enabled(): bool
    {
        return app()->environment(['local', 'staging']) && (bool) config('staging.dev_menu');
    }
}
