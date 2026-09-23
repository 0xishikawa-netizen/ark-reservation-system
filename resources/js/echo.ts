/**
 * Laravel Reverb（WebSocket）クライアントの遅延初期化（§9-12）。
 *
 * 予約台帳など、実際に購読が必要な画面からだけ import して使う
 * （管理画面の全ページで常時接続を張らないため、app.ts では読み込まない）。
 * 接続に失敗しても例外は投げず null を返す——呼び出し側（ScheduleNotifications.vue）は
 * その場合ポーリングだけで動作を継続する（§11 push障害時のfallback）。
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

let echo: Echo<'reverb'> | null = null;
let initAttempted = false;

export function getEcho(): Echo<'reverb'> | null {
    if (initAttempted) {
        return echo;
    }

    initAttempted = true;

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        // Reverb未設定（VITE_REVERB_*が環境にない）。push機能自体を静かに無効化する。
        return null;
    }

    try {
        window.Pusher = Pusher;
        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST as string,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME as string) === 'https',
            enabledTransports: ['ws', 'wss'],
            // 既定（activity 120s + pong 30s）だと切断検知に最大2分半かかり、その間
            // フォールバックpollingへ切り替わらない。店舗運用では取りこぼし検知を
            // 優先し、短めのタイムアウトにする（§11）。
            activityTimeout: 15000,
            pongTimeout: 8000,
        });
        window.Echo = echo;
    } catch {
        echo = null;
    }

    return echo;
}
