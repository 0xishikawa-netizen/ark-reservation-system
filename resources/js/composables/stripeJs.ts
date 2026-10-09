/**
 * Stripe.js の読み込み（Task 11-34 共通化）。
 *
 * 決済画面（予約の事前決済）と会員画面で、既に読み込み中の script タグを扱うかどうかが違うため、
 * 2 つの関数に分けて差をそのまま残している。どちらも失敗時のエラー内容は画面に出さない。
 */

/** Stripe.js は CSP で許可した公式ドメインからのみ読み込む。 */
export const STRIPE_JS_URL = 'https://js.stripe.com/v3';

const STRIPE_JS_LOAD_FAILED = 'stripe-js-load-failed';

function hasStripe(): boolean {
    return Boolean((window as unknown as { Stripe?: unknown }).Stripe);
}

function appendStripeScript(resolve: () => void, reject: (reason: Error) => void): void {
    const script = document.createElement('script');
    script.src = STRIPE_JS_URL;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(STRIPE_JS_LOAD_FAILED));
    document.head.appendChild(script);
}

/** 未読み込みなら script タグを追加して読み込む（予約の事前決済画面）。 */
export const loadStripeJs = (): Promise<void> =>
    new Promise((resolve, reject) => {
        if (hasStripe()) {
            resolve();

            return;
        }
        appendStripeScript(resolve, reject);
    });

/** 同じ script タグが既にあればその読み込み完了を待ち、無ければ追加する（会員画面）。 */
export const loadStripeJsReusingScript = (): Promise<void> =>
    new Promise((resolve, reject) => {
        if (hasStripe()) {
            resolve();

            return;
        }
        const existing = document.querySelector<HTMLScriptElement>(`script[src="${STRIPE_JS_URL}"]`);
        if (existing) {
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener('error', () => reject(new Error(STRIPE_JS_LOAD_FAILED)), { once: true });

            return;
        }
        appendStripeScript(resolve, reject);
    });
