import { computed, ref, watch, type Ref } from 'vue';
import { MESSAGES } from '@/constants/messages';
import { formatReportDate } from './format';

interface DailyRowLike {
    staff_id: number | null;
    business_date?: string;
}

interface StaffOption {
    id: number;
    name: string;
}

/**
 * Reports の日別一覧で使う「表示だけの絞り込み」（スタッフ・日付・勤務/実績ありのみ）。
 * 読み込み済みの行を絞るだけで、上部の表示条件（サーバー側の集計条件）には影響しない。
 */
export function useDailyFilter<T extends DailyRowLike>(
    rows: Ref<T[]>,
    staff: Ref<StaffOption[]>,
    isActive: (row: T) => boolean,
) {
    const staffId = ref<number | null>(null);
    const date = ref<string | null>(null);
    const activeOnly = ref(false);

    const availableStaff = computed(() => {
        const ids = new Set(rows.value.map((row) => row.staff_id));
        return staff.value.filter((member) => ids.has(member.id));
    });
    const dateItems = computed(() => [
        { title: MESSAGES.reporting.dailyAllDates, value: null as string | null },
        ...[...new Set(rows.value.map((row) => row.business_date ?? ''))].filter(Boolean).sort()
            .map((value) => ({ title: formatReportDate(value), value: value as string | null })),
    ]);
    const staffOrder = computed(() => new Map(staff.value.map((member, index) => [member.id, index])));

    // 再読込で選択中のスタッフ・日付が無くなったら「全員」「すべての日付」に戻す。
    watch(rows, () => {
        if (staffId.value !== null && !availableStaff.value.some((member) => member.id === staffId.value)) staffId.value = null;
        if (date.value !== null && !dateItems.value.some((item) => item.value === date.value)) date.value = null;
    });

    const filtered = computed(() => rows.value
        .filter((row) => staffId.value === null || row.staff_id === staffId.value)
        .filter((row) => date.value === null || row.business_date === date.value)
        .filter((row) => !activeOnly.value || isActive(row)));

    const compareDate = (a: T, b: T): number => (a.business_date ?? '').localeCompare(b.business_date ?? '');
    const compareStaff = (a: T, b: T): number =>
        (staffOrder.value.get(a.staff_id ?? -1) ?? Number.MAX_SAFE_INTEGER) - (staffOrder.value.get(b.staff_id ?? -1) ?? Number.MAX_SAFE_INTEGER);

    return { staffId, date, activeOnly, availableStaff, dateItems, filtered, compareDate, compareStaff };
}
