<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Models\Membership;
use RuntimeException;

/**
 * Stripe subscription 操作の Idempotency-Key。
 * 常に DB 永続値 membership_operation_id からのみ導出する（プロセス内で生成し直さない）。
 */
final class MembershipIdempotencyKeyFactory
{
    public function subscriptionCreate(Membership $membership): string
    {
        return $this->for('subscription_create', $membership);
    }

    public function subscriptionCancel(Membership $membership): string
    {
        return $this->for('subscription_cancel', $membership);
    }

    public function subscriptionResume(Membership $membership): string
    {
        return $this->for('subscription_resume', $membership);
    }

    public function subscriptionCancelNow(Membership $membership): string
    {
        return $this->for('subscription_cancel_now', $membership);
    }

    private function for(string $templateName, Membership $membership): string
    {
        $operationId = Membership::query()
            ->whereKey($membership->getKey())
            ->value('membership_operation_id');

        if (! is_string($operationId) || $operationId === '') {
            throw new RuntimeException('永続化済みの membership_operation_id が必要です。');
        }

        $template = config("stripe.idempotency_key_templates.{$templateName}");

        if (! is_string($template) || ! str_contains($template, '{membership_operation_id}')) {
            throw new RuntimeException("Stripe Idempotency-Key テンプレート [{$templateName}] が不正です。");
        }

        $key = str_replace('{membership_operation_id}', $operationId, $template);

        if (str_contains($key, '{') || str_contains($key, '}')) {
            throw new RuntimeException("Stripe Idempotency-Key テンプレート [{$templateName}] に未解決の値があります。");
        }

        return $key;
    }
}
