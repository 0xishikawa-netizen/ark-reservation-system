import type { Ref } from 'vue';

/**
 * 帳票の月・売上基準などの切替（Task 11-34 共通化）。
 * 値が変わったときだけ保持値を更新して再読込する。同じ値なら何もしない。
 */
export function changeAndReload<T>(target: Ref<T>, value: T, reload: () => unknown): void {
    if (value === target.value) return;
    target.value = value;
    void reload();
}
