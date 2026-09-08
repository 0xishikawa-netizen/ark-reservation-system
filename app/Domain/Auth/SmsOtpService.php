<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Sms\SmsSender;
use App\Models\MfaSmsChallenge;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Security\PiiHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * SMS OTP の発行と検証（PLAN §13 / phase-05-5 §3-1）。
 *
 * 絶対規則:
 * - **OTP の平文を DB へ保存しない**（`code_hash` のみ）。
 * - **OTP をログ・監査・例外メッセージへ出さない。**
 * - 電話番号の平文を保存・ログ出力しない（`phone_hmac` で照合）。
 * - 一回使用で無効。試行上限・再送上限・IP 上限をすべて適用する。
 */
final class SmsOtpService
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_PHONE_VERIFICATION = 'phone_verification';

    public function __construct(
        private readonly SmsSender $sms,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * OTP を発行して送信する。
     *
     * @param  string  $phone  平文電話番号（保存しない。送信にのみ使う）
     *
     * @throws ValidationException
     */
    public function send(
        User $user,
        string $phone,
        string $purpose,
        ?string $ip = null,
    ): MfaSmsChallenge {
        $phoneHmac = PiiHasher::phoneHmac($phone);

        if ($phoneHmac === null) {
            throw ValidationException::withMessages([
                'phone' => '電話番号の形式が正しくありません。',
            ]);
        }

        $this->assertResendAllowed($user, $ip);

        // CSPRNG。rand() / mt_rand() は使わない。
        $length = max(4, (int) config('mfa.sms.otp.length', 6));
        $code = str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );

        $challenge = DB::transaction(function () use ($user, $purpose, $phoneHmac, $code, $ip): MfaSmsChallenge {
            // 同一目的の未使用チャレンジは無効化する（並行して複数有効にしない）。
            MfaSmsChallenge::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            return MfaSmsChallenge::query()->create([
                'user_id' => $user->getKey(),
                'purpose' => $purpose,
                'phone_hmac' => $phoneHmac,
                // ハッシュのみ保存
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addSeconds((int) config('mfa.sms.otp.ttl_seconds', 300)),
                'sent_at' => now(),
                'ip' => $ip,
            ]);
        });

        // 送信は transaction の外（外部通信）。
        $this->sms->send($phone, $this->message($code));

        $this->auditLogger->log(
            'mfa.sms.sent',
            null,
            // コードも電話番号平文も残さない
            "SMS OTP 送信 user#{$user->getKey()} 目的={$purpose} challenge#{$challenge->id}",
            $user,
        );

        return $challenge;
    }

    /**
     * OTP を検証する。成功時に一回限りで消費する。
     *
     * @throws ValidationException
     */
    public function verify(User $user, string $code, string $purpose): MfaSmsChallenge
    {
        $this->assertVerifyRateLimit($user);

        $challenge = MfaSmsChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if ($challenge === null) {
            $this->fail($user, '認証コードが見つかりません。もう一度送信してください。');
        }

        if ($challenge->expires_at->isPast()) {
            $this->fail($user, '認証コードの有効期限が切れました。もう一度送信してください。');
        }

        $maxAttempts = (int) config('mfa.sms.otp.max_attempts', 5);

        if ($challenge->attempts >= $maxAttempts) {
            // 上限到達済みのチャレンジは失効させる。
            $challenge->forceFill(['used_at' => now()])->save();
            $this->fail($user, '認証コードの試行回数が上限に達しました。もう一度送信してください。');
        }

        // 検証と消費を 1 transaction で行い、並行リクエストによる二重消費を防ぐ。
        $verified = DB::transaction(function () use ($challenge, $code, $maxAttempts): bool {
            $locked = MfaSmsChallenge::query()
                ->whereKey($challenge->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->used_at !== null || $locked->attempts >= $maxAttempts) {
                return false;
            }

            $locked->increment('attempts');

            if (! Hash::check($code, $locked->code_hash)) {
                if ($locked->attempts + 1 >= $maxAttempts) {
                    $locked->forceFill(['used_at' => now()])->save();
                }

                return false;
            }

            // 一回使用で無効化
            $locked->forceFill(['used_at' => now()])->save();

            return true;
        });

        if (! $verified) {
            $this->auditLogger->log(
                'mfa.sms.failed',
                null,
                "SMS OTP 検証失敗 user#{$user->getKey()} challenge#{$challenge->id}",
                $user,
            );

            $this->fail($user, '認証コードが正しくありません。');
        }

        $this->auditLogger->log(
            'mfa.sms.verified',
            null,
            "SMS OTP 検証成功 user#{$user->getKey()} challenge#{$challenge->id}",
            $user,
        );

        return $challenge->refresh();
    }

    /** @throws ValidationException */
    private function assertResendAllowed(User $user, ?string $ip): void
    {
        $minInterval = (int) config('mfa.sms.resend.min_interval_seconds', 60);

        $recent = MfaSmsChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('sent_at', '>', now()->subSeconds($minInterval))
            ->exists();

        if ($recent) {
            throw ValidationException::withMessages([
                'code' => "認証コードの再送は {$minInterval} 秒後に可能になります。",
            ]);
        }

        $perHour = (int) config('mfa.sms.resend.max_per_hour', 5);
        $sentThisHour = MfaSmsChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('sent_at', '>', now()->subHour())
            ->count();

        if ($sentThisHour >= $perHour) {
            throw ValidationException::withMessages([
                'code' => '認証コードの送信回数が上限に達しました。時間をおいてお試しください。',
            ]);
        }

        // IP 単位の濫用（SMS 費用の不正消費）も止める。
        if ($ip !== null) {
            $ipLimit = (int) config('mfa.sms.rate_limit.send_per_ip_per_hour', 10);

            if (RateLimiter::tooManyAttempts("mfa-sms-send:{$ip}", $ipLimit)) {
                throw ValidationException::withMessages([
                    'code' => '認証コードの送信回数が上限に達しました。時間をおいてお試しください。',
                ]);
            }

            RateLimiter::hit("mfa-sms-send:{$ip}", 3600);
        }
    }

    /** @throws ValidationException */
    private function assertVerifyRateLimit(User $user): void
    {
        $key = "mfa-sms-verify:{$user->getKey()}";
        $perMinute = (int) config('mfa.sms.rate_limit.verify_per_minute', 5);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw ValidationException::withMessages([
                'code' => '試行回数が多すぎます。しばらくしてからお試しください。',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    /** @throws ValidationException */
    private function fail(User $user, string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }

    private function message(string $code): string
    {
        return "【ARK Conditioning】認証コード: {$code}\n"
            .'このコードは他人に教えないでください。';
    }
}
