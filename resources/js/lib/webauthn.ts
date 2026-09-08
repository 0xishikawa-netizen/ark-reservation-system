/**
 * WebAuthn のブラウザ API ヘルパー。
 *
 * 暗号処理は一切行わない。ブラウザ標準の `navigator.credentials` を呼び、
 * サーバー（laravel/passkeys）とやり取りする base64url ⇄ ArrayBuffer 変換だけを担う。
 * challenge / origin / RP ID の検証はすべてサーバー側の責務。
 */

const base64UrlToBuffer = (value: string): ArrayBuffer => {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(padded.padEnd(padded.length + ((4 - (padded.length % 4)) % 4), '='));
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
};

const bufferToBase64Url = (buffer: ArrayBuffer): string => {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (let i = 0; i < bytes.byteLength; i += 1) {
        binary += String.fromCharCode(bytes[i]);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

export const isPasskeySupported = (): boolean =>
    typeof window !== 'undefined' &&
    typeof window.PublicKeyCredential !== 'undefined' &&
    typeof navigator.credentials?.create === 'function';

interface PublicKeyOptions {
    challenge: string;
    user?: { id: string; name: string; displayName: string };
    excludeCredentials?: { id: string; type: string; transports?: string[] }[];
    allowCredentials?: { id: string; type: string; transports?: string[] }[];
    [key: string]: unknown;
}

/** サーバーが返した登録オプションを WebAuthn の型へ変換する。 */
const toCreationOptions = (options: PublicKeyOptions): PublicKeyCredentialCreationOptions =>
    ({
        ...options,
        challenge: base64UrlToBuffer(options.challenge),
        user: options.user
            ? { ...options.user, id: base64UrlToBuffer(options.user.id) }
            : undefined,
        excludeCredentials: options.excludeCredentials?.map((credential) => ({
            ...credential,
            id: base64UrlToBuffer(credential.id),
        })),
    }) as unknown as PublicKeyCredentialCreationOptions;

const toRequestOptions = (options: PublicKeyOptions): PublicKeyCredentialRequestOptions =>
    ({
        ...options,
        challenge: base64UrlToBuffer(options.challenge),
        allowCredentials: options.allowCredentials?.map((credential) => ({
            ...credential,
            id: base64UrlToBuffer(credential.id),
        })),
    }) as unknown as PublicKeyCredentialRequestOptions;

/**
 * サーバーへ送信する WebAuthn ペイロード。
 * Inertia の RequestPayload としてそのまま渡せる形（文字列と入れ子オブジェクトのみ）。
 */
export interface PasskeyPayload {
    id: string;
    rawId: string;
    type: string;
    response: Record<string, string>;
    [key: string]: string | Record<string, string>;
}

/** 登録レスポンスをサーバーへ送れる形へ整形する。 */
const serializeCredential = (credential: PublicKeyCredential): PasskeyPayload => {
    const response = credential.response as AuthenticatorAttestationResponse &
        AuthenticatorAssertionResponse;

    const payload: PasskeyPayload = {
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {},
    };

    const inner = payload.response;
    inner.clientDataJSON = bufferToBase64Url(response.clientDataJSON);

    if (response.attestationObject) {
        inner.attestationObject = bufferToBase64Url(response.attestationObject);
    }
    if (response.authenticatorData) {
        inner.authenticatorData = bufferToBase64Url(response.authenticatorData);
    }
    if (response.signature) {
        inner.signature = bufferToBase64Url(response.signature);
    }
    if (response.userHandle) {
        inner.userHandle = bufferToBase64Url(response.userHandle);
    }

    return payload;
};

/** Passkey を新規登録する。 */
export const createPasskey = async (
    options: PublicKeyOptions,
): Promise<PasskeyPayload> => {
    const credential = (await navigator.credentials.create({
        publicKey: toCreationOptions(options),
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new Error('passkey-creation-cancelled');
    }

    return serializeCredential(credential);
};

/** 既存の Passkey で認証する（ログイン / 再認証の両方で使う）。 */
export const getPasskeyAssertion = async (
    options: PublicKeyOptions,
): Promise<PasskeyPayload> => {
    const credential = (await navigator.credentials.get({
        publicKey: toRequestOptions(options),
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new Error('passkey-assertion-cancelled');
    }

    return serializeCredential(credential);
};
