/**
 * 文言テンプレートの `{名前}` を値で置き換える（Task 11-34 共通化）。
 *
 * `String.prototype.replace(文字列, 値)` は、値に `$&` や `$1` などが含まれると特殊な置換として解釈してしまう
 * （顧客名・メニュー名などに含まれると表示がずれる）。ここでは置換関数を使うため、値はそのまま差し込まれる。
 * テンプレートに無い名前は無視し、値の無い `{名前}` はそのまま残す。
 */
export type MessageParams = Record<string, string | number | null | undefined>;

export function fillMessage(template: string, params: MessageParams): string {
    return template.replace(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g, (placeholder: string, name: string): string => {
        if (!Object.prototype.hasOwnProperty.call(params, name)) {
            return placeholder;
        }

        const value = params[name];

        return value === null || value === undefined ? '' : String(value);
    });
}
