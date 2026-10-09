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
        :title="MESSAGES.boardUi.slotChoicePanel.title"
        icon="mdi-calendar-blank-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <!-- 選んだ枠（日付・開始時間・担当）を、予約パネルと同じ3セルのカードで確認させる。 -->
        <div class="sch__when">
            <div class="sch__when-cell">
                <span class="sch__when-label">{{ MESSAGES.boardUi.slotChoicePanel.date }}</span>
                <span class="sch__when-value">{{ fmtDay(date) }}</span>
            </div>
            <div class="sch__when-cell">
                <span class="sch__when-label">{{ MESSAGES.boardUi.slotChoicePanel.startTime }}</span>
                <span v-if="time" class="sch__when-value sch__when-value--time">{{ time }}</span>
                <span v-else class="sch__when-value sch__when-value--muted">{{ MESSAGES.common.emptyValue }}</span>
            </div>
            <div v-if="staffName" class="sch__when-cell sch__when-cell--staff">
                <span class="sch__when-label">{{ MESSAGES.boardUi.slotChoicePanel.staff }}</span>
                <span class="sch__when-value">{{ staffName }}</span>
            </div>
        </div>

        <p class="sch__lead">{{ MESSAGES.reservation.slotChoiceQuestion }}</p>

        <!-- 予約とスタッフ予定を同じ形のボタンで並べる（予約が上）。 -->
        <div class="sch__choices">
            <PanelChoiceCard
                kind="reservation"
                :title="MESSAGES.boardUi.slotChoicePanel.createReservation"
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
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: var(--ark-space-3);
    padding: var(--ark-space-3);
    background: rgba(var(--v-theme-primary), 0.05);
    border: 1px solid rgba(var(--v-theme-primary), 0.14);
    border-radius: 10px;
}

.sch__when-cell {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
}

.sch__when-cell--staff {
    grid-column: 1 / -1;
    padding-top: var(--ark-space-2);
    border-top: 1px dashed rgba(var(--v-theme-primary), 0.2);
}

.sch__when-label {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.62);
}

.sch__when-value {
    font-size: 0.8125rem;
    font-weight: 700;
    line-height: 1.3;
    word-break: break-word;
}

.sch__when-value--time {
    font-size: 1rem;
    font-weight: 800;
    color: rgb(var(--v-theme-primary));
    font-variant-numeric: tabular-nums;
}

.sch__when-value--muted {
    color: rgba(var(--v-theme-on-surface), 0.5);
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
