<?php

declare(strict_types=1);

namespace App\Domain\Auth\Sms;

/**
 * テスト専用。送信内容をメモリに保持し、assert できるようにする。
 *
 * 本番環境にはバインドしない（AppServiceProvider で testing のみ）。
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{phone: string, message: string}> */
    private array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    /** @return list<array{phone: string, message: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    /** 直近に送った本文から OTP を取り出す（テスト用）。 */
    public function lastCode(): ?string
    {
        $last = end($this->sent);

        if ($last === false) {
            return null;
        }

        preg_match('/\d{4,10}/', $last['message'], $matches);

        return $matches[0] ?? null;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
