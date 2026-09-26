<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { ReportFilterBar, ReportFilterField } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface DayNote {
    business_date: string;
    day: number;
    weekday: string;
    business_condition: string;
    reflection: string;
}

const props = defineProps<{ month: string; days: DayNote[]; editable: boolean; indexEndpoint: string }>();
const labels = MESSAGES.reporting;
const month = ref(props.month);
const drafts = ref<DayNote[]>(props.days.map((day) => ({ ...day })));
const originals = ref<Record<string, { business_condition: string; reflection: string }>>(
    Object.fromEntries(props.days.map((day) => [day.business_date, {
        business_condition: day.business_condition,
        reflection: day.reflection,
    }])),
);
const saving = ref<string | null>(null);
const saved = ref<string | null>(null);
const error = ref<string | null>(null);

const isDirty = (day: DayNote): boolean => {
    const original = originals.value[day.business_date];
    return original !== undefined && (day.business_condition !== original.business_condition || day.reflection !== original.reflection);
};

function changeMonth(value: string): void {
    if (value === month.value) return;
    month.value = value;
    router.get(props.indexEndpoint, { month: value }, { preserveState: false });
}

function save(day: DayNote): void {
    if (!props.editable || !isDirty(day) || saving.value !== null) return;
    saving.value = day.business_date;
    saved.value = null;
    error.value = null;
    const submitted = { business_condition: day.business_condition, reflection: day.reflection };
    router.put(`${props.indexEndpoint}/${day.business_date}`, submitted, {
        preserveScroll: true,
        onSuccess: () => {
            originals.value[day.business_date] = submitted;
            saved.value = day.business_date;
        },
        onError: () => { error.value = day.business_date; },
        onFinish: () => { saving.value = null; },
    });
}
</script>

<template>
    <Head :title="labels.dailyNotesTitle" />
    <PageHeader :title="labels.dailyNotesTitle" :subtitle="labels.dailyNotesSubtitle" />
    <ReportFilterBar>
        <ReportFilterField size="md">
            <MonthField :model-value="month" :label="labels.dailyNotesMonth" density="compact" data-testid="daily-notes-month" @update:model-value="changeMonth" />
        </ReportFilterField>
        <template v-if="!editable" #meta>{{ labels.dailyNotesReadOnly }}</template>
    </ReportFilterBar>
    <SectionCard :title="labels.dailyNotesTitle">
        <div class="table-scroll">
            <table class="notes-table">
                <thead><tr><th>{{ labels.dailyNotesDay }}</th><th>{{ labels.dailyNotesCondition }}</th><th>{{ labels.dailyNotesReflection }}</th><th>{{ labels.dailyNotesAction }}</th></tr></thead>
                <tbody>
                    <tr v-for="day in drafts" :key="day.business_date" :data-testid="`daily-note-${day.business_date}`">
                        <th>{{ day.day }}日（{{ day.weekday }}）</th>
                        <td><textarea v-model="day.business_condition" :readonly="!editable" :aria-label="`${day.day}日の${labels.dailyNotesCondition}`" rows="4" maxlength="10000" /></td>
                        <td><textarea v-model="day.reflection" :readonly="!editable" :aria-label="`${day.day}日の${labels.dailyNotesReflection}`" rows="4" maxlength="10000" /></td>
                        <td class="save-cell">
                            <v-btn
                                v-if="editable"
                                color="primary"
                                variant="flat"
                                size="small"
                                prepend-icon="mdi-content-save-outline"
                                :disabled="!isDirty(day) || saving !== null"
                                :loading="saving === day.business_date"
                                @click="save(day)"
                            >
                                {{ saving === day.business_date ? labels.dailyNotesSaving : labels.dailyNotesSave }}
                            </v-btn>
                            <small v-if="isDirty(day)" class="unsaved">{{ labels.dailyNotesUnsaved }}</small>
                            <small v-else-if="saved === day.business_date" role="status">{{ labels.dailyNotesSaved }}</small>
                            <small v-if="error === day.business_date" role="alert" class="error">{{ labels.dailyNotesFailed }}</small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>

<style scoped>
.table-scroll { overflow-x: auto; }
.notes-table { width: 100%; min-width: 900px; border-collapse: collapse; }
.notes-table th, .notes-table td { border-bottom: 1px solid #dfe3e8; padding: 12px; vertical-align: top; }
.notes-table thead th { text-align: left; background: #edf4f8; }
.notes-table tbody th { min-width: 105px; white-space: nowrap; }
.notes-table textarea { width: 100%; min-width: 280px; min-height: 96px; resize: vertical; border: 1px solid #aeb8c5; border-radius: 6px; padding: 9px; font: inherit; line-height: 1.5; }
.notes-table textarea:read-only { background: #f8fafc; }
.save-cell { min-width: 160px; }
.save-cell small { display: block; margin-top: 8px; }
.unsaved { color: #995e00; }.error { color: #b42318; }
</style>
