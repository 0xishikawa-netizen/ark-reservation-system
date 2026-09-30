<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;

/**
 * ログアウト時にブラウザ履歴（Inertia が保存したページデータ）を消す。
 *
 * Inertia は表示したページの props（顧客名・予約など）をブラウザ履歴に保存し、「戻る」でサーバーへ問い合わせずに
 * 再表示する。ログアウト後に「戻る」を押すと、店舗の共用端末で前の利用者の顧客情報が見えていた（全面検証 2026-09-30）。
 * 履歴は EncryptHistory ミドルウェアで暗号化しているので、ここで鍵を捨てると古い履歴は復号できず、
 * Inertia はサーバーへ取り直しに行き、未ログインとしてログイン画面へ送られる。
 */
final class HistoryClearingLogoutResponse implements LogoutResponse
{
    public function toResponse($request)
    {
        Inertia::clearHistory();

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect(Fortify::redirects('logout', '/'));
    }
}
