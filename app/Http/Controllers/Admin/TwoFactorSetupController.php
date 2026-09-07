<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorSetupController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $passwordConfirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $passwordTimeout = (int) config('auth.password_timeout', 900);

        // Fortify のパスワード確認後にセットアップ画面へ戻す。
        if (now()->timestamp - $passwordConfirmedAt >= $passwordTimeout) {
            $request->session()->put('url.intended', route('admin.two-factor-setup'));
        }

        return Inertia::render('Admin/Profile/TwoFactorSetup', [
            'twoFactorPending' => $user?->two_factor_secret !== null
                && $user->two_factor_confirmed_at === null,
            'twoFactorEnabled' => $user?->two_factor_confirmed_at !== null,
        ]);
    }
}
