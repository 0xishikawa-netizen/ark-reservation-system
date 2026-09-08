<?php

declare(strict_types=1);

namespace App\Domain\Auth\Sms;

use App\Support\Security\PiiHasher;
use Illuminate\Support\Facades\Log;

/**
 * local / staging 用。実際には送信しない。
 *
 * **OTP 本文も電話番号平文もログに残さない。**
 * 「いつ・誰宛に送ろうとしたか」だけを HMAC 化した宛先で記録する。
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('mfa.sms.dispatched', [
            'driver' => 'log',
            // 平文の電話番号を残さない
            'destination_hmac' => PiiHasher::phoneHmac($phone),
            'length' => mb_strlen($message),
        ]);
    }
}
