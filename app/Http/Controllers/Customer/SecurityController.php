<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Controller;
use App\Models\UserSocialAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 顧客のセキュリティ設定（連携アカウント / パスワード）。
 *
 * Google の連携・解除自体は {@see GoogleAuthController} が
 * 担当し（`password.confirm` 必須）、ここは状態表示のみ。**token / secret は返さない。**
 */
class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        $google = $user->socialAccounts()
            ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
            ->first();

        return Inertia::render('Customer/Profile/Security', [
            'google' => [
                'linked' => $google !== null,
                'email' => $google?->provider_email,
                'linked_at' => $google?->created_at?->format('Y-m-d'),
            ],
            'hasPassword' => ! blank($user->password),
        ]);
    }
}
