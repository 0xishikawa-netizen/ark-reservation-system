import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { KEEP_ALIVE_INTERVAL_MS, useSessionKeepAlive } from './sessionKeepAlive';

const Host = defineComponent({
    setup() {
        const { sessionLost } = useSessionKeepAlive();
        return () => h('div', { 'data-lost': String(sessionLost.value) });
    },
});

describe('管理画面のセッション維持', () => {
    beforeEach(() => { vi.useFakeTimers(); });
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

    it('開いている間は一定間隔でサーバーに触れ、時間が経っても自分からはログアウトしない', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(new Response(null, { status: 204 }));
        const host = mount(Host);
        expect(fetchMock).not.toHaveBeenCalled();

        // 何時間放置しても、定期的な確認だけで自動ログアウトや画面遷移は起きない。
        await vi.advanceTimersByTimeAsync(KEEP_ALIVE_INTERVAL_MS * 30);
        expect(fetchMock).toHaveBeenCalledTimes(30);
        expect(fetchMock.mock.calls[0][0]).toBe('/admin/session/keep-alive');
        expect(host.get('div').attributes('data-lost')).toBe('false');
        host.unmount();
    });

    it('本当に失効した（401）時だけ案内を出し、以後は確認を止める（無限リトライしない）', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(new Response(null, { status: 401 }));
        const host = mount(Host);
        await vi.advanceTimersByTimeAsync(KEEP_ALIVE_INTERVAL_MS);
        await flushPromises();
        expect(host.get('div').attributes('data-lost')).toBe('true');

        await vi.advanceTimersByTimeAsync(KEEP_ALIVE_INTERVAL_MS * 5);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        host.unmount();
    });

    it('通信断はログアウト扱いにせず、次の定期確認で再度試す', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockRejectedValue(new TypeError('network'));
        const host = mount(Host);
        await vi.advanceTimersByTimeAsync(KEEP_ALIVE_INTERVAL_MS * 2);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(host.get('div').attributes('data-lost')).toBe('false');
        host.unmount();
    });
});

describe('管理画面に無操作タイムアウトの仕組みが無いこと（構造）', () => {
    const sources = import.meta.glob(
        ['../layouts/**/*.{ts,vue}', '../Pages/Admin/**/*.{ts,vue}', '../components/admin/**/*.{ts,vue}', '../composables/**/*.ts'],
        { query: '?raw', import: 'default', eager: true },
    ) as Record<string, string>;
    const production = Object.entries(sources).filter(([path]) => !path.endsWith('.spec.ts'));

    it('idle / inactivity / countdown / 自動ログアウトの実装が無い', () => {
        expect(production.length).toBeGreaterThan(10);
        for (const [path, source] of production) {
            expect(source, path).not.toMatch(/idle.?time|inactiv(e|ity).?time|countdown|auto.?logout|session.?expir/i);
        }
    });

    it('ログアウトはヘッダーの明示的な操作だけから行う', () => {
        const logoutCallers = production.filter(([, source]) => /['"`]\/logout['"`]/.test(source)).map(([path]) => path);
        expect(logoutCallers.sort()).toEqual(['../layouts/AdminLayout.vue', '../layouts/CustomerLayout.vue']);
        for (const path of logoutCallers) {
            // ボタンから呼ぶ logout 関数の中だけで /logout を送る（タイマー等からは呼ばない）。
            expect(sources[path], path).toMatch(/const logout = \(\)(: void)? => \{\s*router\.post\('\/logout'\);\s*\};/);
            expect(sources[path], path).toMatch(/@click="logout"/);
        }
    });
});
