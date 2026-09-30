<script setup lang="ts">
import PanelChoiceCard from '@/components/admin/PanelChoiceCard.vue';
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

        <!-- 予約とスタッフ予定を同じ形のボタンで並べる（予約が上）。 -->
        <div class="sch__choices">
            <PanelChoiceCard
                kind="reservation"
                title="予約を作成"
                :description="MESSAGES.schedule.choiceReservationDesc"
                data-testid="choose-reservation"
                @click="emit('chooseReservation')"
            />
            <PanelChoiceCard
                kind="block"
                :title="MESSAGES.schedule.staffBlock"
                :description="MESSAGES.schedule.choiceBlockDesc"
                data-testid="choose-block"
                @click="emit('chooseBlock')"
            />
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

</style>
