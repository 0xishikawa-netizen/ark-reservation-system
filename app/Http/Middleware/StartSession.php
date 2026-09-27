<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * 画面が fetch() で取る JSON（予約パネル・空き枠・集計データなど）を「直前の URL」に記録しない。
 *
 * Laravel は X-Requested-With の無い GET を直前の URL として保存する。fetch() はこのヘッダを付けないため、
 * 予約パネルを開いた後に Referer の無いリクエスト（URL直接入力・ブックマーク等）が検証エラーで back() すると、
 * 生の JSON 画面へ戻されていた（全面検証 2026-09-27 で発見）。JSON を求める GET は画面遷移ではないので除外する。
 */
final class StartSession extends BaseStartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->expectsJson()) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
