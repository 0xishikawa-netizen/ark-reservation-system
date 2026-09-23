/**
 * 新規予約通知の音。音声ファイルを持たず Web Audio API で合成する。
 *
 * 「通知音」らしい澄んだベル系の音（FM 合成＋軽いエコー）を中心にしている。
 * 店内は音楽・会話・トレーニング器具の音があるため、会話や音楽とかぶりにくい
 * 高めの音域・はっきりした立ち上がりで作り、コンプレッサーで音割れさせずに音圧を上げている。
 *
 * ブラウザは「ユーザー操作の前に音を出す」ことを禁止しているため、
 * 画面上の最初のクリック/キー入力で AudioContext を起こしておく（unlockNotificationSound）。
 * それまでに届いた通知は音なし（通知カードは通常どおり表示される）。
 */

type AudioContextCtor = typeof AudioContext;

/** 選べる通知音（サーバー側 NotificationSettings::SOUND_TYPES と揃える）。 */
export type NotificationSoundType =
    | 'glass'
    | 'message'
    | 'tritone'
    | 'sparkle'
    | 'harp'
    | 'calendar'
    | 'drop'
    | 'chime'
    | 'bell'
    | 'marimba'
    | 'pop'
    | 'alert';

/** 繰り返し方（サーバー側 NotificationSettings::REPEAT_MODES と揃える）。 */
export type NotificationRepeatMode = 'once' | 'three' | 'until_ack';

export interface NotificationSoundOption {
    value: NotificationSoundType;
    label: string;
    description: string;
    /** にぎやかな店内でも気づきやすい音か。 */
    loud: boolean;
}

export const NOTIFICATION_SOUND_OPTIONS: NotificationSoundOption[] = [
    { value: 'glass', label: 'キラン', description: '高く澄んだ2音', loud: true },
    { value: 'message', label: 'ピコン', description: '短く明るい上昇音', loud: true },
    { value: 'tritone', label: 'トゥルルン', description: '軽やかな3音', loud: true },
    { value: 'sparkle', label: 'キラリン', description: 'きらきらした上昇音', loud: true },
    { value: 'harp', label: 'ポロロン', description: 'ハープ風の分散和音', loud: true },
    { value: 'calendar', label: 'リンリン', description: '澄んだベルを3回', loud: true },
    { value: 'drop', label: 'ポコン', description: '水滴のような丸い音', loud: true },
    { value: 'chime', label: 'ピンポン', description: '定番の2音チャイム', loud: false },
    { value: 'bell', label: 'ベル', description: '余韻のある澄んだ音', loud: false },
    { value: 'marimba', label: 'マリンバ', description: 'やわらかい3音', loud: false },
    { value: 'pop', label: 'ポップ', description: '短く控えめな音', loud: false },
    { value: 'alert', label: 'ピピピ', description: '短い電子音3回', loud: false },
];

export const NOTIFICATION_REPEAT_OPTIONS: { value: NotificationRepeatMode; label: string; description: string }[] = [
    { value: 'once', label: '1回', description: '届いた時に1回だけ鳴らす' },
    { value: 'three', label: '3回', description: '少し間をあけて3回鳴らす' },
    { value: 'until_ack', label: '確認するまで', description: '通知を開くか閉じるまで15秒ごとに鳴らす（最大5分）' },
];

export const DEFAULT_NOTIFICATION_SOUND: NotificationSoundType = 'glass';
export const DEFAULT_NOTIFICATION_VOLUME = 80;
export const DEFAULT_NOTIFICATION_REPEAT: NotificationRepeatMode = 'three';

let context: AudioContext | null = null;
let output: AudioNode | null = null;

function audioContextCtor(): AudioContextCtor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const w = window as unknown as {
        AudioContext?: AudioContextCtor;
        webkitAudioContext?: AudioContextCtor;
    };

    return w.AudioContext ?? w.webkitAudioContext ?? null;
}

function getContext(): AudioContext | null {
    if (context !== null) {
        return context;
    }

    const Ctor = audioContextCtor();

    if (Ctor === null) {
        return null;
    }

    try {
        context = new Ctor();
        // 音割れさせずに音圧を上げるためのコンプレッサー（全音共通の出口）。
        const compressor = context.createDynamicsCompressor();
        compressor.threshold.setValueAtTime(-18, context.currentTime);
        compressor.knee.setValueAtTime(6, context.currentTime);
        compressor.ratio.setValueAtTime(8, context.currentTime);
        compressor.attack.setValueAtTime(0.002, context.currentTime);
        compressor.release.setValueAtTime(0.15, context.currentTime);
        compressor.connect(context.destination);
        output = compressor;
    } catch {
        return null;
    }

    return context;
}

/** ユーザー操作のイベント内で呼ぶと、以後は自動で音を鳴らせるようになる。 */
export async function unlockNotificationSound(): Promise<void> {
    const ctx = getContext();

    if (ctx !== null && ctx.state === 'suspended') {
        await ctx.resume().catch(() => undefined);
    }
}

interface Tone {
    /** 周波数（Hz） */
    frequency: number;
    /** 指定すると、鳴っている間にこの周波数まで音程を動かす（サイレン等） */
    toFrequency?: number;
    /** 鳴り始め（秒・再生開始からの相対） */
    at: number;
    /** 減衰しきるまでの長さ（秒） */
    duration: number;
    type?: OscillatorType;
    volume?: number;
    /** true なら減衰させず一定音量で鳴らす（電子音向け） */
    sustain?: boolean;
    /**
     * FM 合成でベルらしい倍音を足す。ratio=変調の周波数比、index=かかり具合（時間とともに弱まる）。
     * 非整数の比にするとグラス・金属ベルのようなきらっとした響きになる。
     */
    fm?: { ratio: number; index: number };
}

function tone(ctx: AudioContext, dest: AudioNode, start: number, t: Tone): void {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    const startAt = start + t.at;
    const endAt = startAt + t.duration;
    const peak = t.volume ?? 0.35;

    osc.type = t.type ?? 'sine';
    osc.frequency.setValueAtTime(t.frequency, startAt);

    if (t.toFrequency !== undefined) {
        osc.frequency.exponentialRampToValueAtTime(t.toFrequency, endAt);
    }

    // クリックノイズが出ないよう、立ち上がり・減衰をなめらかにする。
    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.01);

    if (t.sustain) {
        gain.gain.setValueAtTime(peak, Math.max(endAt - 0.02, startAt + 0.011));
    }

    gain.gain.exponentialRampToValueAtTime(0.0001, endAt);

    if (t.fm !== undefined) {
        const mod = ctx.createOscillator();
        const modGain = ctx.createGain();
        const depth = t.frequency * t.fm.index;

        mod.frequency.setValueAtTime(t.frequency * t.fm.ratio, startAt);
        // 立ち上がりは倍音多め→だんだん丸い音に（ベルの自然な減衰）。
        modGain.gain.setValueAtTime(depth, startAt);
        modGain.gain.exponentialRampToValueAtTime(Math.max(depth * 0.05, 0.0001), endAt);
        mod.connect(modGain);
        modGain.connect(osc.frequency);
        mod.start(startAt);
        mod.stop(endAt + 0.05);
    }

    osc.connect(gain);
    gain.connect(dest);
    osc.start(startAt);
    osc.stop(endAt + 0.05);
}

/** グラス・ベル系の1音（FM 合成）。 */
function bellNote(frequency: number, at: number, duration = 1.1, volume = 0.45, ratio = 3.5, index = 1.2): Tone {
    return { frequency, at, duration, volume, fm: { ratio, index } };
}

function beeps(startAt: number, count: number, gap: number, frequency: number, length = 0.07, volume = 0.3): Tone[] {
    return Array.from({ length: count }, (_, i) => ({
        frequency,
        at: startAt + i * gap,
        duration: length,
        type: 'square' as OscillatorType,
        volume,
        sustain: true,
    }));
}

const PATTERNS: Record<NotificationSoundType, Tone[]> = {
    // キラン：E6 → B6 のグラス音
    glass: [bellNote(1318.5, 0, 1.2, 0.5, 3.5, 1.1), bellNote(1975.5, 0.14, 1.5, 0.5, 3.5, 1.1)],
    // ピコン：短い上昇2音（C6 → G6）
    message: [
        { frequency: 1046.5, at: 0, duration: 0.16, type: 'triangle', volume: 0.5, fm: { ratio: 2, index: 0.4 } },
        { frequency: 1568, at: 0.09, duration: 0.5, type: 'triangle', volume: 0.5, fm: { ratio: 2, index: 0.4 } },
    ],
    // トゥルルン：G6 → D6 → B6 の軽やかな3音
    tritone: [bellNote(1568, 0, 0.5, 0.45, 2.01, 0.8), bellNote(1174.7, 0.12, 0.5, 0.45, 2.01, 0.8), bellNote(1975.5, 0.24, 1.0, 0.45, 2.01, 0.8)],
    // キラリン：C6 E6 G6 C7 E7 の速い上昇アルペジオ
    sparkle: [1046.5, 1318.5, 1568, 2093, 2637].map((frequency, i) => bellNote(frequency, i * 0.06, 0.7 + i * 0.12, 0.38, 4.1, 0.9)),
    // ポロロン：G5 B5 D6 G6 のハープ風
    harp: [784, 987.8, 1174.7, 1568].map((frequency, i) => ({
        frequency,
        at: i * 0.09,
        duration: 1.3,
        type: 'triangle' as OscillatorType,
        volume: 0.42,
        fm: { ratio: 1, index: 0.6 },
    })),
    // リンリン：A6 のベルを2回（2回目は少し高く）
    calendar: [bellNote(1760, 0, 0.9, 0.45, 5.3, 1.4), bellNote(1760, 0.28, 0.9, 0.45, 5.3, 1.4), bellNote(2217.5, 0.56, 1.3, 0.45, 5.3, 1.4)],
    // ポコン：音程がすっと下がる丸い音を2回
    drop: [
        { frequency: 1800, toFrequency: 700, at: 0, duration: 0.22, volume: 0.6 },
        { frequency: 2100, toFrequency: 820, at: 0.26, duration: 0.26, volume: 0.6 },
    ],
    // ── 控えめ ──
    chime: [
        { frequency: 1046.5, at: 0, duration: 0.35 },
        { frequency: 784, at: 0.18, duration: 0.6 },
    ],
    bell: [
        { frequency: 880, at: 0, duration: 1.2, type: 'triangle', volume: 0.3 },
        { frequency: 1760, at: 0, duration: 0.8, volume: 0.08 },
        { frequency: 880, at: 0.45, duration: 1.2, type: 'triangle', volume: 0.25 },
        { frequency: 1760, at: 0.45, duration: 0.8, volume: 0.06 },
    ],
    marimba: [
        { frequency: 659.3, at: 0, duration: 0.3, volume: 0.4 },
        { frequency: 784, at: 0.12, duration: 0.3, volume: 0.4 },
        { frequency: 1046.5, at: 0.24, duration: 0.5, volume: 0.4 },
    ],
    pop: [
        { frequency: 1318.5, at: 0, duration: 0.09, volume: 0.3 },
        { frequency: 1568, at: 0.11, duration: 0.12, volume: 0.3 },
    ],
    alert: beeps(0, 3, 0.18, 1975.5, 0.12, 0.12),
};

/** 1回分の音の長さ（秒）。繰り返す時の間隔の計算に使う。 */
export function notificationSoundLength(type: NotificationSoundType): number {
    return Math.max(...PATTERNS[type].map((t) => t.at + t.duration));
}

export function isNotificationSoundType(value: unknown): value is NotificationSoundType {
    return typeof value === 'string' && value in PATTERNS;
}

export function isNotificationRepeatMode(value: unknown): value is NotificationRepeatMode {
    return value === 'once' || value === 'three' || value === 'until_ack';
}

/**
 * 通知音を1回鳴らす。鳴らせない環境（未操作・非対応）では何もしない。
 *
 * @param volume 0〜100
 */
export function playNotificationSound(
    type: NotificationSoundType = DEFAULT_NOTIFICATION_SOUND,
    volume: number = DEFAULT_NOTIFICATION_VOLUME,
): void {
    const ctx = getContext();

    if (ctx === null || output === null || ctx.state !== 'running') {
        return;
    }

    const dest = output;

    const play = (): void => {
        const master = ctx.createGain();
        // 聴感に合わせて2乗カーブにする（50% でもちゃんと小さく聞こえるように）。
        const level = Math.min(Math.max(volume, 0), 100) / 100;
        master.gain.setValueAtTime(level * level * 1.6, ctx.currentTime);
        master.connect(dest);

        // 通知音らしい広がりを出す軽いエコー（元の音＋遅れて小さく2〜3回返ってくる音）。
        const delay = ctx.createDelay(1);
        const feedback = ctx.createGain();
        const wet = ctx.createGain();
        delay.delayTime.setValueAtTime(0.16, ctx.currentTime);
        feedback.gain.setValueAtTime(0.28, ctx.currentTime);
        wet.gain.setValueAtTime(0.3, ctx.currentTime);
        master.connect(delay);
        delay.connect(feedback);
        feedback.connect(delay);
        delay.connect(wet);
        wet.connect(dest);

        const now = ctx.currentTime + 0.02;

        for (const t of PATTERNS[type] ?? PATTERNS[DEFAULT_NOTIFICATION_SOUND]) {
            tone(ctx, master, now, t);
        }

        // エコーの循環接続を音の終了後に切り、長時間開いた台帳で音源を残さない。
        setTimeout(() => {
            master.disconnect();
            delay.disconnect();
            feedback.disconnect();
            wet.disconnect();
        }, (notificationSoundLength(type) + 2) * 1000);
    };

    play();
}
