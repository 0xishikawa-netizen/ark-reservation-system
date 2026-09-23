/**
 * 予約台帳の左パネル内部「戻る」（Peak Manager非依存・ARK独自の軽量実装）が使う
 * スタック操作の純粋関数。ブラウザ履歴は増やさず、直前のパネル状態だけを数件覚えておく。
 * Vue コンポーネントから独立させているのは、Vitest でそのままユニットテストできるようにするため。
 */

export function pushPanelHistoryStack<T>(stack: T[], entry: T, limit: number): T[] {
    return [...stack, entry].slice(-limit);
}

export interface PopResult<T> {
    target: T | null;
    rest: T[];
}

export function popPanelHistoryStack<T>(stack: T[]): PopResult<T> {
    if (stack.length === 0) {
        return { target: null, rest: stack };
    }

    return { target: stack[stack.length - 1], rest: stack.slice(0, -1) };
}
