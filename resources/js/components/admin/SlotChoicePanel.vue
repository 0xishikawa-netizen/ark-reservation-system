<script setup lang="ts">
import PanelShell from '@/components/admin/PanelShell.vue';
import { MESSAGES } from '@/constants/messages';

withDefaults(defineProps<{
    date: string;
    time: string | null;
    staffName?: string | null;
    canGoBack?: boolean;
}>(), {
    staffName: null,
    canGoBack: false,
});

const emit = defineEmits<{
    close: [];
    back: [];
    chooseReservation: [];
    chooseBlock: [];
}>();

function fmtDay(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(new Date(y, m - 1, d));

    return `${m}/${d}（${weekday}）`;
}
</script>

<template>
    <PanelShell
        title="この時間に追加"
        icon="mdi-calendar-blank-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <!-- 選んだ枠（日付・時刻・スタッフ）を最初に大きく確認させる。 -->
        <div class="sch__when">
            <div class="sch__when-head">
                <span class="sch__date">{{ fmtDay(date) }}</span>
                <span v-if="staffName" class="sch__staff">{{ staffName }}</span>
            </div>
            <div class="sch__time">
                <v-icon icon="mdi-clock-outline" size="16" class="sch__time-icon" />
                <span v-if="time">{{ time }}</span>
                <span v-else class="sch__time--empty">時間未選択</span>
                <span v-if="time" class="sch__time-suffix">から</span>
            </div>
        </div>

        <p class="sch__lead">{{ MESSAGES.reservation.slotChoiceQuestion }}</p>

        <div class="sch__choices">
            <button
                type="button"
                class="sch__choice sch__choice--reservation"
                @click="emit('chooseReservation')"
            >
                <span class="sch__choice-icon">
                    <v-icon icon="mdi-calendar-plus-outline" size="20" />
                </span>
                <span class="sch__choice-body">
                    <span class="sch__choice-title">予約を入れる</span>
                    <span class="sch__choice-desc">顧客・メニューを選んで登録</span>
                </span>
                <v-icon icon="mdi-chevron-right" size="18" class="sch__choice-arrow" />
            </button>

            <button
                type="button"
                class="sch__choice sch__choice--block"
                @click="emit('chooseBlock')"
            >
                <span class="sch__choice-icon">
                    <v-icon icon="mdi-clock-plus-outline" size="20" />
                </span>
                <span class="sch__choice-body">
                    <span class="sch__choice-title">予定を入れる</span>
                    <span class="sch__choice-desc">休憩・ミーティングなど</span>
                </span>
                <v-icon icon="mdi-chevron-right" size="18" class="sch__choice-arrow" />
            </button>
        </div>
    </PanelShell>
</template>

<style scoped>
.sch__when {
    padding: var(--ark-space-3);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-primary), 0.06);
    border: 1px solid rgba(var(--v-theme-primary), 0.14);
}

.sch__when-head {
    display: flex;
    align-items: baseline;
    gap: var(--ark-space-2);
}

.sch__date {
    font-size: 0.75rem;
    font-weight: 800;
}

.sch__staff {
    margin-left: auto;
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.sch__time {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
    font-size: 1.125rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: rgb(var(--v-theme-primary));
}

.sch__time-icon {
    margin-right: 2px;
}

.sch__time--empty {
    font-size: 0.8125rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.sch__time-suffix {
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.sch__lead {
    margin: 0;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: rgb(var(--v-theme-primary));
}

.sch__choices {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-2);
}

/* 縦に積んだ一覧型（横2分割だと文字が詰まって読みにくいため）。
   予約＝navy／予定＝azure で、左端の色帯だけ変えて控えめに区別する。 */
.sch__choice {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--ark-space-3);
    width: 100%;
    padding: var(--ark-space-3);
    padding-left: calc(var(--ark-space-3) + 4px);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.15);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    overflow: hidden;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.12s ease, background-color 0.12s ease;
}

/* 左端の色帯。 */
.sch__choice::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 4px;
}

.sch__choice--reservation::before {
    background: rgb(var(--v-theme-primary));
}

.sch__choice--block::before {
    background: rgb(var(--v-theme-secondary));
}

.sch__choice--reservation:hover {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.06);
}

.sch__choice--block:hover {
    border-color: rgb(var(--v-theme-secondary));
    background: rgba(var(--v-theme-secondary), 0.07);
}

.sch__choice-icon {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
}

.sch__choice--block .sch__choice-icon {
    background: rgba(var(--v-theme-secondary), 0.14);
    color: rgb(var(--v-theme-secondary));
}

.sch__choice-body {
    display: flex;
    flex: 1 1 auto;
    min-width: 0;
    flex-direction: column;
    gap: 1px;
}

.sch__choice-title {
    font-size: 0.8125rem;
    font-weight: 800;
    color: rgb(var(--v-theme-on-surface));
}

.sch__choice-desc {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.sch__choice-arrow {
    flex: 0 0 auto;
    color: rgba(var(--v-theme-on-surface), 0.4);
}
</style>
