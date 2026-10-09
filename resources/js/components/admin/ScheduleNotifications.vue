<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    DEFAULT_NOTIFICATION_REPEAT,
    DEFAULT_NOTIFICATION_SOUND,
    DEFAULT_NOTIFICATION_VOLUME,
    type NotificationRepeatMode,
    type NotificationSoundType,
    notificationSoundLength,
    playNotificationSound,
    unlockNotificationSound,
} from '@/composables/notificationSound';
import { MESSAGES } from '@/constants/messages';
import { getEcho } from '@/echo';
import { fillMessage } from '@/utils/message';

/** 通知音を3回鳴らす際に各音の後へ空ける秒数。 */
const REPEAT_SOUND_GAP_SECONDS = 0.8;
/** 秒をミリ秒へ変換する倍率。 */
const MILLISECONDS_PER_SECOND = 1000;

interface NotificationItem {
    id: number;
    customer_name: string;
    service_name: string;
    source: string;
    source_label: string;
    starts_at: string;
    date: string;
    created_at: string;
}

export interface NotificationSoundSetting {
    enabled: boolean;
    type: NotificationSoundType;
    /** 10〜100 */
    volume: number;
    repeat: NotificationRepeatMode;
}

const props = withDefaults(
    defineProps<{
        /** 新しい予約通知が届いた時の音（設定 > 通知設定）。 */
        sound?: NotificationSoundSetting;
    }>(),
    {
        sound: () => ({
            enabled: true,
            type: DEFAULT_NOTIFICATION_SOUND,
            volume: DEFAULT_NOTIFICATION_VOLUME,
            repeat: DEFAULT_NOTIFICATION_REPEAT,
        }),
    },
);

const emit = defineEmits<{
    select: [payload: { date: string; reservationId: number }];
}>();

const items = ref<NotificationItem[]>([]);
const MAX_VISIBLE = 5;
// push（Reverb）が繋がっている間はこの間隔まで落とす（取りこぼし対策として止めはしない・§11）。
const POLL_INTERVAL_CONNECTED_MS = 120000;
// push未接続・切断時はこの間隔で通常どおりポーリングする（従来どおりの取りこぼし防止）。
const POLL_INTERVAL_FALLBACK_MS = 20000;
let pollTimer: ReturnType<typeof setInterval> | null = null;
let active = false;

// 一度でも届いた通知ID。新着判定（＝音を鳴らすか）に使う。dismiss しても残す
// （既読にした通知がポーリングの間に再度返ってきても二度鳴らさないため）。
const seenIds = new Set<number>();
// 画面を開いた直後の初回取得分は「既にあった通知」なので鳴らさない。
let initialFetchDone = false;
let fetchRequestId = 0;

// 「確認するまで」鳴らす対象（まだ開いても閉じてもいない新着の通知ID）。
const unacknowledgedIds = new Map<number, number>();
const UNTIL_ACK_INTERVAL_MS = 15000;
const UNTIL_ACK_MAX_MS = 5 * 60 * 1000;
let untilAckTimer: ReturnType<typeof setInterval> | null = null;
const repeatTimers: ReturnType<typeof setTimeout>[] = [];

function playOnce(): void {
    playNotificationSound(props.sound.type, props.sound.volume);
}

function stopUntilAck(): void {
    if (untilAckTimer !== null) {
        clearInterval(untilAckTimer);
        untilAckTimer = null;
    }
}

function acknowledge(id: number): void {
    unacknowledgedIds.delete(id);

    if (unacknowledgedIds.size === 0) {
        stopUntilAck();
    }
}

/** 設定の「繰り返し」に合わせて鳴らす。 */
function ring(newIds: number[]): void {
    playOnce();

    if (props.sound.repeat === 'three') {
        // 1回分の長さ＋少しの間をあけて、合計3回鳴らす。
        const gapMs = (notificationSoundLength(props.sound.type) + REPEAT_SOUND_GAP_SECONDS) * MILLISECONDS_PER_SECOND;

        for (const n of [1, 2]) {
            const timer = setTimeout(() => {
                repeatTimers.splice(repeatTimers.indexOf(timer), 1);
                playOnce();
            }, gapMs * n);
            repeatTimers.push(timer);
        }

        return;
    }

    if (props.sound.repeat === 'until_ack') {
        for (const id of newIds) {
            unacknowledgedIds.set(id, Date.now() + UNTIL_ACK_MAX_MS);
        }

        if (untilAckTimer === null) {
            untilAckTimer = setInterval(() => {
                for (const [id, expiresAt] of unacknowledgedIds) {
                    if (Date.now() >= expiresAt) {
                        unacknowledgedIds.delete(id);
                    }
                }

                if (unacknowledgedIds.size === 0) {
                    stopUntilAck();

                    return;
                }

                playOnce();
            }, UNTIL_ACK_INTERVAL_MS);
        }
    }
}

/** まだ見ていない通知IDが含まれていれば鳴らす。 */
function notifyArrivals(incoming: NotificationItem[]): void {
    const newIds: number[] = [];

    for (const item of incoming) {
        if (!seenIds.has(item.id)) {
            seenIds.add(item.id);
            newIds.push(item.id);
        }
    }

    if (newIds.length > 0 && initialFetchDone && props.sound.enabled) {
        ring(newIds);
    }
}

const visibleItems = computed(() => items.value.slice(0, MAX_VISIBLE));
const overflowCount = computed(() => Math.max(0, items.value.length - MAX_VISIBLE));

/** push・pollingどちらから届いても、同じ予約IDの通知はUI上に1件だけにする（§12）。 */
function mergeNotifications(incoming: NotificationItem[]): void {
    const byId = new Map(items.value.map((item) => [item.id, item]));

    for (const item of incoming) {
        byId.set(item.id, item);
    }

    items.value = [...byId.values()].sort((a, b) => b.created_at.localeCompare(a.created_at));
}

function addPushedNotification(item: NotificationItem): void {
    if (items.value.some((existing) => existing.id === item.id)) {
        return;
    }

    if (initialFetchDone) {
        fetchRequestId++;
    }
    notifyArrivals([item]);
    mergeNotifications([item]);
}

async function fetchNotifications(): Promise<void> {
    const requestId = ++fetchRequestId;

    try {
        const response = await fetch('/admin/schedule/notifications', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const payload = (await response.json()) as { notifications: NotificationItem[] };

        if (!active || requestId !== fetchRequestId) {
            return;
        }

        // サーバー側の「現在未読」一覧が正。dismiss済みはここで自然に消える。
        notifyArrivals(payload.notifications);
        if (!initialFetchDone && items.value.length > 0) {
            mergeNotifications(payload.notifications);
        } else {
            items.value = payload.notifications;
        }

        // 他の端末で既読にされた通知は、ここでも「確認済み」扱いにして鳴らすのをやめる。
        const currentIds = new Set(payload.notifications.map((item) => item.id));

        for (const id of unacknowledgedIds.keys()) {
            if (!currentIds.has(id)) {
                acknowledge(id);
            }
        }
        initialFetchDone = true;
    } catch {
        /* ポーリング失敗時は次回まで静かに待つ */
    }
}

function startPolling(intervalMs: number): void {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
    }

    pollTimer = setInterval(() => void fetchNotifications(), intervalMs);
}

function onPushConnected(): void {
    startPolling(POLL_INTERVAL_CONNECTED_MS);
}

function onPushDisconnected(): void {
    startPolling(POLL_INTERVAL_FALLBACK_MS);
}

/** Reverb（WebSocket）購読。接続できなければ何もせず、フォールバックのポーリングだけで動く（§9-11）。 */
function subscribeToPush(): void {
    const echo = getEcho();

    if (echo === null) {
        return;
    }

    try {
        echo.connector.pusher.connection.bind('connected', onPushConnected);
        echo.connector.pusher.connection.bind('disconnected', onPushDisconnected);
        echo.connector.pusher.connection.bind('unavailable', onPushDisconnected);

        echo.private('schedule-notifications')
            .listen('.reservation.created', (payload: NotificationItem) => {
                addPushedNotification(payload);
            });
    } catch {
        /* Echo/Reverb が使えない環境でも致命的にしない（フォールバックpollingのみで継続） */
    }
}

function fmtWhen(row: NotificationItem): string {
    const [, m, d] = row.date.split('-').map(Number);

    return `${m}/${d} ${row.starts_at.slice(11, 16)}`;
}

/** Laravel は web ミドルウェアで XSRF-TOKEN cookie を発行する（meta タグは無いため cookie から読む）。 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function dismiss(row: NotificationItem): Promise<void> {
    fetchRequestId++;
    acknowledge(row.id);
    items.value = items.value.filter((item) => item.id !== row.id);

    try {
        await fetch(`/admin/schedule/notifications/${row.id}/dismiss`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
        });
    } catch {
        /* 既読反映に失敗しても次回ポーリングで再送されるだけなので致命的ではない */
    }
}

function select(row: NotificationItem): void {
    acknowledge(row.id);
    emit('select', { date: row.date, reservationId: row.id });
}

onMounted(() => {
    active = true;
    // ブラウザの自動再生制限対策：画面の最初の操作で音を出せる状態にしておく。
    window.addEventListener('pointerdown', unlockNotificationSound, { once: true });
    window.addEventListener('keydown', unlockNotificationSound, { once: true });
    void fetchNotifications();
    // 接続できるまでは従来どおりのフォールバック間隔でポーリングする。
    startPolling(POLL_INTERVAL_FALLBACK_MS);
    subscribeToPush();
});

onBeforeUnmount(() => {
    active = false;
    stopUntilAck();

    for (const timer of repeatTimers) {
        clearTimeout(timer);
    }

    window.removeEventListener('pointerdown', unlockNotificationSound);
    window.removeEventListener('keydown', unlockNotificationSound);

    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    const echo = getEcho();
    echo?.connector.pusher.connection.unbind('connected', onPushConnected);
    echo?.connector.pusher.connection.unbind('disconnected', onPushDisconnected);
    echo?.connector.pusher.connection.unbind('unavailable', onPushDisconnected);
    echo?.leave('schedule-notifications');
});
</script>

<template>
    <div v-if="visibleItems.length" class="sn" aria-live="polite">
        <div v-for="row in visibleItems" :key="row.id" class="sn__card" @click="select(row)">
            <v-icon icon="mdi-calendar-check-outline" size="18" class="sn__icon" />
            <div class="sn__body">
                <div class="sn__title">{{ MESSAGES.boardUi.scheduleNotifications.onlineReservation }}</div>
                <div class="sn__main">{{ row.customer_name }} ／ {{ row.service_name }}</div>
                <div class="sn__sub">{{ fmtWhen(row) }}・{{ row.source_label }}</div>
            </div>
            <button
                type="button"
                class="sn__close"
                :aria-label="MESSAGES.boardUi.scheduleNotifications.close"
                @click.stop="dismiss(row)"
            >
                <v-icon icon="mdi-close" size="16" />
            </button>
        </div>
        <div v-if="overflowCount > 0" class="sn__more">
            {{ fillMessage(MESSAGES.boardUi.scheduleNotifications.overflow, { count: String(overflowCount) }) }}
        </div>
    </div>
</template>

<style scoped>
.sn {
    position: fixed;
    right: var(--ark-space-4);
    bottom: var(--ark-space-4);
    z-index: 3000;
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-2);
    width: min(92vw, 340px);
}

.sn__card {
    display: flex;
    align-items: flex-start;
    gap: var(--ark-space-2);
    padding: var(--ark-space-3);
    background: rgb(var(--v-theme-surface));
    border: 1px solid #d9dee5;
    border-left: 4px solid rgb(var(--v-theme-primary));
    border-radius: var(--ark-radius);
    box-shadow: 0 8px 24px rgb(18 25 60 / 18%);
    cursor: pointer;
}

.sn__icon {
    flex: 0 0 auto;
    margin-top: 1px;
    color: rgb(var(--v-theme-primary));
}

.sn__body {
    flex: 1 1 auto;
    min-width: 0;
}

.sn__title {
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: rgb(var(--v-theme-primary));
}

.sn__main {
    margin-top: 2px;
    font-size: 0.8125rem;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sn__sub {
    margin-top: 2px;
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.sn__close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 24px;
    height: 24px;
    margin: -4px -4px 0 0;
    border: 0;
    border-radius: 999px;
    background: none;
    color: rgba(var(--v-theme-on-surface), 0.7);
    cursor: pointer;
}

.sn__close:hover {
    background: rgba(var(--v-theme-on-surface), 0.08);
}

.sn__more {
    padding: 4px var(--ark-space-3);
    font-size: 0.75rem;
    text-align: center;
    color: rgba(var(--v-theme-on-surface), 0.72);
}
</style>
