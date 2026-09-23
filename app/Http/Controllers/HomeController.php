<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Customer\DashboardController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return Inertia::render('Welcome', [
                'appName' => config('app.name'),
            ]);
        }

        if ($user->hasRole('customer')) {
            // ダッシュボードは顧客データの集約。/mypage/* と同じく、メール未認証では見せない。
            if (! $user->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }

            // メソッド依存（Request / CustomerDashboardQuery）をコンテナに解決させる。
            return app()->call([app(DashboardController::class), '__invoke']);
        }

        if ($user->hasAnyRole(['staff', 'manager', 'admin'])) {
            // 店舗スタッフが一番長く使うのはブッキングボード。ログイン直後はそこへ着地させ、
            // 予約を見られない権限のユーザーだけダッシュボードへ戻す。
            return $user->can('reservations.view')
                ? redirect()->route('admin.schedule.index')
                : redirect()->route('admin.dashboard');
        }

        abort(403);
    }
}
