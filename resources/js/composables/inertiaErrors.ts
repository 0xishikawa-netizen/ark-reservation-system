/**
 * Inertia の onError で受け取るエラーから、画面に出す最初のメッセージを取り出す。
 *
 * サーバーは予約の競合などを「reservation」という名前付きエラーバッグで返すため、
 * 呼び出し側が errorBag を指定していないと { reservation: { reservation: '…' } } の
 * 入れ子で届き、そのまま表示すると `{ "reservation": "…" }` のような生の JSON になる。
 * 入れ子でも平らでも、最初に見つかった文字列を返す。
 */
export function firstErrorMessage(errors: unknown): string | null {
    if (typeof errors === 'string') {
        return errors === '' ? null : errors;
    }

    if (Array.isArray(errors)) {
        for (const item of errors) {
            const message = firstErrorMessage(item);

            if (message !== null) {
                return message;
            }
        }

        return null;
    }

    if (errors !== null && typeof errors === 'object') {
        for (const value of Object.values(errors as Record<string, unknown>)) {
            const message = firstErrorMessage(value);

            if (message !== null) {
                return message;
            }
        }
    }

    return null;
}
