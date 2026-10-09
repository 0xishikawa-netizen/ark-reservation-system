import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * マスタ一覧の有効/無効スイッチ（Task 11-33 M-6 / 11-34 共通化）。
 *
 * - スイッチの `@update:model-value` から呼び、切り替え後の値を `{basePath}/{id}/active` へ明示して送る
 *   （反転ではなく目標値を送るので、連打や画面の古い状態で逆の値を送らない）。
 * - 送信中の行は `isPending(id)` が true になる。スイッチの `:disabled` に渡して二重送信を防ぐ。
 */
export function useMasterActiveToggle(basePath: string): {
    isPending: (id: number) => boolean;
    toggle: (item: { id: number; is_active: boolean }, active: boolean | null) => void;
} {
    const pendingIds = reactive(new Set<number>());

    const toggle = (item: { id: number; is_active: boolean }, active: boolean | null): void => {
        if (active === null || active === item.is_active || pendingIds.has(item.id)) {
            return;
        }

        pendingIds.add(item.id);
        router.patch(`${basePath}/${item.id}/active`, { active }, {
            preserveScroll: true,
            onFinish: () => pendingIds.delete(item.id),
        });
    };

    return { isPending: (id: number): boolean => pendingIds.has(id), toggle };
}
