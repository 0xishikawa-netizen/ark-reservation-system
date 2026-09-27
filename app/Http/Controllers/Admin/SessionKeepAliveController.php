<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * 管理画面を開いている間のセッション維持。
 *
 * 管理画面では無操作・時間経過を理由に自動ログアウトしない（docs/SESSION_POLICY.md）。
 * 画面が定期的にこのエンドポイントへ触れることで、Laravel セッションの寿命（SESSION_LIFETIME）が
 * 施術・接客中に切れないようにする。認証・権限・アカウント有効性・MFA は admin グループの
 * middleware がそのまま検査するため、無効化されたアカウント等はここでも通常どおり失効する。
 */
final class SessionKeepAliveController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
