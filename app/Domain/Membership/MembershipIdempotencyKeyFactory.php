<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Models\Membership;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Stripe subscription 操作の Idempotency-Key。
 * 根は常に DB 永続値 membership_operation_id（プロセス内で生成し直さない）。
 *
 * - create / cancel_now: 固定キー。retry / reload / job 再実行で新しい subscription を作らない。
 * - cancel / resume: toggle 操作なので {bucket}（UTC の時）を足す。cancel→resume→cancel のように
 *   同じ状態へ戻したとき、Stripe の 24h 冪等キャッシュが古い応答を返して実際には適用されない事故を防ぐ。
 *   同一時内の二重送信（ダブルクリック等）は同じ bucket なので従来どおり 1 回に収束する。
 */
final class MembershipIdempotencyKeyFactory
{
    public function subscriptionCreate(Membership $membership): string
    {
        return $this->for('subscription_create', $membership);
    }

    public function subscriptionCancel(Membership $membership): string
    {
        return $this->for('subscription_cancel', $membership, ['bucket' => $this->toggleBucket()]);
    }

    public function subscriptionResume(Membership $membership): string
    {
        return $this->for('subscription_resume', $membership, ['bucket' => $this->toggleBucket()]);
    }

    public function subscriptionCancelNow(Membership $membership): string
    {
        return $this->for('subscription_cancel_now', $membership);
    }

    /** UTC の「時」バケット（YmdH）。 */
    private function toggleBucket(): string
    {
        return Carbon::now('UTC')->format('YmdH');
    }

    /**
     * @param  array<string, string>  $extra  追加のプレースホルダ置換（{name} => value）
     */
    private function for(string $templateName, Membership $membership, array $extra = []): string
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

        foreach ($extra as $name => $value) {
            $key = str_replace('{'.$name.'}', $value, $key);
        }

        if (str_contains($key, '{') || str_contains($key, '}')) {
            throw new RuntimeException("Stripe Idempotency-Key テンプレート [{$templateName}] に未解決の値があります。");
        }

        return $key;
    }
}
