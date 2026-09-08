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
            // メソッド依存（Request / CustomerDashboardQuery）をコンテナに解決させる。
            return app()->call([app(DashboardController::class), '__invoke']);
        }

        if ($user->hasAnyRole(['staff', 'manager', 'admin'])) {
            return redirect()->route('admin.dashboard');
        }

        abort(403);
    }
}
