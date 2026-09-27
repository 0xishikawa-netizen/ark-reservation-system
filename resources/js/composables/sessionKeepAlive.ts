import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * 管理画面のセッション維持。
 *
 * ARK 管理画面は無操作・時間経過を理由に自動ログアウトしない（docs/SESSION_POLICY.md）。
 * 施術・接客・会計の間に画面を開いたままにしても Laravel セッションの寿命（SESSION_LIFETIME）で
 * 切れないよう、画面を開いている間は一定間隔でサーバーに触れる。
 *
 * ここには無操作タイマー・カウントダウン・自動ログアウトは無い。サーバーが 401 / 419 を返した
 * （アカウント無効化・明示ログアウト・サーバー側失効など、本当に失効した）場合だけ
 * sessionLost を立てて以後の確認を止める（無限リトライしない）。
 */
export const KEEP_ALIVE_INTERVAL_MS = 10 * 60 * 1000;
/** タブ復帰時の確認を連打しないための最短間隔。 */
export const KEEP_ALIVE_MIN_GAP_MS = 60 * 1000;

export function useSessionKeepAlive(url = '/admin/session/keep-alive'): { sessionLost: Ref<boolean>; ping: () => Promise<void> } {
    const sessionLost = ref(false);
    let timer: ReturnType<typeof setInterval> | null = null;
    let lastPing = 0;

    const stop = (): void => {
        if (timer !== null) clearInterval(timer);
        timer = null;
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('focus', onVisible);
    };

    const ping = async (): Promise<void> => {
        if (sessionLost.value) return;
        lastPing = Date.now();
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const toLogin = response.redirected && new URL(response.url, window.location.origin).pathname === '/login';
            if (response.status === 401 || response.status === 419 || toLogin) {
                sessionLost.value = true;
                stop();
            }
        } catch {
            // 通信断はログアウト扱いにしない。次の定期確認で再度試す。
        }
    };

    function onVisible(): void {
        if (document.visibilityState === 'visible' && Date.now() - lastPing >= KEEP_ALIVE_MIN_GAP_MS) void ping();
    }

    onMounted(() => {
        lastPing = Date.now();
        timer = setInterval(() => void ping(), KEEP_ALIVE_INTERVAL_MS);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);
    });

    onBeforeUnmount(stop);

    return { sessionLost, ping };
}
