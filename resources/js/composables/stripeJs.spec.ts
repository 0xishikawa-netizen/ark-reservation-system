import { afterEach, describe, expect, it } from 'vitest';
import { loadStripeJs, loadStripeJsReusingScript, STRIPE_JS_URL } from './stripeJs';

const stripeWindow = window as unknown as { Stripe?: unknown };

function stripeScripts(): HTMLScriptElement[] {
    return Array.from(document.querySelectorAll<HTMLScriptElement>(`script[src="${STRIPE_JS_URL}"]`));
}

afterEach(() => {
    stripeScripts().forEach((script) => script.remove());
    delete stripeWindow.Stripe;
});

describe('loadStripeJs', () => {
    it('読み込み済みなら script を追加しない', async () => {
        stripeWindow.Stripe = () => undefined;
        await expect(loadStripeJs()).resolves.toBeUndefined();
        expect(stripeScripts()).toHaveLength(0);
    });

    it('未読み込みなら script を追加し、load で完了する', async () => {
        const promise = loadStripeJs();
        const scripts = stripeScripts();
        expect(scripts).toHaveLength(1);
        scripts[0].dispatchEvent(new Event('load'));
        await expect(promise).resolves.toBeUndefined();
    });

    it('既存の script があっても新しく追加する（予約の事前決済画面の挙動）', () => {
        document.head.appendChild(Object.assign(document.createElement('script'), { src: STRIPE_JS_URL }));
        void loadStripeJs().catch(() => undefined);
        expect(stripeScripts()).toHaveLength(2);
    });

    it('読み込み失敗で reject する', async () => {
        const promise = loadStripeJs();
        stripeScripts()[0].dispatchEvent(new Event('error'));
        await expect(promise).rejects.toThrow('stripe-js-load-failed');
    });
});

describe('loadStripeJsReusingScript', () => {
    it('既存の script があれば追加せずに load を待つ', async () => {
        const existing = Object.assign(document.createElement('script'), { src: STRIPE_JS_URL });
        document.head.appendChild(existing);
        const promise = loadStripeJsReusingScript();
        expect(stripeScripts()).toHaveLength(1);
        existing.dispatchEvent(new Event('load'));
        await expect(promise).resolves.toBeUndefined();
    });

    it('既存の script の読み込み失敗で reject する', async () => {
        const existing = Object.assign(document.createElement('script'), { src: STRIPE_JS_URL });
        document.head.appendChild(existing);
        const promise = loadStripeJsReusingScript();
        existing.dispatchEvent(new Event('error'));
        await expect(promise).rejects.toThrow('stripe-js-load-failed');
    });

    it('script が無ければ追加する', async () => {
        const promise = loadStripeJsReusingScript();
        const scripts = stripeScripts();
        expect(scripts).toHaveLength(1);
        scripts[0].dispatchEvent(new Event('load'));
        await expect(promise).resolves.toBeUndefined();
    });
});
