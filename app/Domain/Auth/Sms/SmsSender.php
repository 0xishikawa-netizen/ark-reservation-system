<?php

declare(strict_types=1);

namespace App\Domain\Auth\Sms;

/**
 * SMS 送信の抽象（PLAN §5 の Gateway 方針に合わせる）。
 *
 * provider 固有のコードを Controller / Service へ直接書かない。
 * 実 provider は未契約のため、Phase 5.5 では log / fake のみを実装する
 * （到達率・価格の比較は docs/MFA_MODERNIZATION.md §5、決定は OPEN_QUESTIONS）。
 */
interface SmsSender
{
    /**
     * @param  string  $phone  E.164 もしくは国内表記の平文電話番号（**保存・ログ出力しない**）
     * @param  string  $message  本文（OTP を含みうる。**ログ出力しない**）
     */
    public function send(string $phone, string $message): void;
}
