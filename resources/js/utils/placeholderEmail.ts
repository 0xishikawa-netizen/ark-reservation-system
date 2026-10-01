/**
 * 電話予約などの仮登録で自動採番するダミーメール（@ark.invalid）。本物のアドレスではないので、
 * 画面では「値なし」として扱い、そのまま見せない。
 */
export function realEmail(email: string | null | undefined): string | null {
    if (!email || email.toLowerCase().endsWith('@ark.invalid')) {
        return null;
    }

    return email;
}
