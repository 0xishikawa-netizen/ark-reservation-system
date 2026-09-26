<script setup lang="ts">
import { MESSAGES } from '@/constants/messages';

/**
 * 日別一覧の「全員 ↔ 特定スタッフ」切替。読み込み済みデータの表示だけを絞り、
 * 上部の表示条件（サーバー側の絞り込み）とは独立に動く。
 */
defineProps<{
    modelValue: number | null;
    staff: { id: number; name: string }[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

function select(value: number | null | undefined): void {
    emit('update:modelValue', value ?? null);
}
</script>

<template>
    <v-chip-group
        :model-value="modelValue"
        class="report-staff-chips"
        mandatory
        selected-class="report-staff-chips__chip--selected"
        :aria-label="MESSAGES.reporting.staffName"
        @update:model-value="select"
    >
        <v-chip :value="null" size="small" variant="outlined" data-testid="staff-chip-all">
            <v-icon start icon="mdi-account-multiple-outline" size="16" />
            {{ MESSAGES.reporting.dailyAllStaff }}
        </v-chip>
        <v-chip
            v-for="member in staff"
            :key="member.id"
            :value="member.id"
            size="small"
            variant="outlined"
            :data-testid="`staff-chip-${member.id}`"
        >
            {{ member.name }}
        </v-chip>
    </v-chip-group>
</template>

<style scoped>
.report-staff-chips {
    padding: 0;
}

.report-staff-chips :deep(.v-chip) {
    margin: 2px 6px 2px 0;
    border-color: #d5dbe5;
    color: rgba(var(--v-theme-on-surface), 0.78);
    font-weight: 600;
}

.report-staff-chips :deep(.report-staff-chips__chip--selected) {
    border-color: rgb(var(--v-theme-primary));
    background: rgb(var(--v-theme-primary));
    color: #ffffff;
}
</style>
