<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payment\Webhook\StripeWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe webhook 受け口（PLAN §9 / phase-05 §5）。
 *
 * - 署名検証必須。検証できないリクエストは一切処理しない。
 * - 攻撃的な rate limit は掛けない（Stripe の正常な retry を阻害しないため）。
 * - 失敗時は 500 を返して Stripe に再送させる。再送は event.id の UNIQUE で無害化される。
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookProcessor $processor): Response
    {
        $secret = (string) config('stripe.webhook_secret');

        if ($secret === '') {
            // 署名検証できない構成で受け付けてはいけない。
            return response('webhook secret not configured', 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            // 署名不一致・不正な payload。詳細はログにも残さない。
            return response('invalid signature', 400);
        }

        $result = $processor->process($event->toArray());

        // failed のみ再送させる。duplicate / ignored / processed は 200 で完了扱い。
        return response($result, $result === 'failed' ? 500 : 200);
    }
}
