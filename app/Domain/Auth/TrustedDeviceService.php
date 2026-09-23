<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 「この端末を信頼する」による TOTP チャレンジ省略（PLAN 外の追加機能）。
 *
 * Cookie には selector（公開のルックアップキー）と平文トークンだけを載せ、
 * DB には token_hash のみを保存する（remember-me トークンと同じ設計。
 * 平文トークンを DB へ保存しない）。
 */
final class TrustedDeviceService
{
    public function remember(User $user, Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        $selector = Str::random(24);
        $validator = Str::random(40);

        TrustedDevice::query()->create([
            'user_id' => $user->getKey(),
            'selector' => $selector,
            'token_hash' => Hash::make($validator),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($this->ttlDays()),
        ]);

        Cookie::queue(Cookie::make(
            name: $this->cookieName(),
            value: $selector.'.'.$validator,
            minutes: $this->ttlDays() * 24 * 60,
            secure: app()->environment('production'),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public function isTrusted(User $user, Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $cookie = $request->cookie($this->cookieName());

        if (! is_string($cookie) || ! str_contains($cookie, '.')) {
            return false;
        }

        [$selector, $validator] = explode('.', $cookie, 2);

        $device = TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('selector', $selector)
            ->first();

        if ($device === null || $device->expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($validator, $device->token_hash)) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /** TOTP の無効化・再生成時に、既存の信頼済み端末をすべて無効化する。 */
    public function forgetAll(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->getKey())->delete();

        Cookie::queue(Cookie::forget($this->cookieName()));
    }

    private function enabled(): bool
    {
        return (bool) config('mfa.trusted_device.enabled', true);
    }

    private function ttlDays(): int
    {
        return (int) config('mfa.trusted_device.ttl_days', 365);
    }

    private function cookieName(): string
    {
        return (string) config('mfa.trusted_device.cookie', 'ark_trusted_device');
    }
}
