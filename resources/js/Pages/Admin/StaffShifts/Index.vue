<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface StaffOption {
    user_id: number;
    display_name: string;
    is_bookable: boolean;
}

interface StaffShiftListItem {
    id: number;
    staff_id: number;
    staff_display_name: string;
    work_date: string;
    start_at: string;
    end_at: string;
}

interface Filters {
    staff_id: number | null;
    from: string;
    to: string;
}

const props = defineProps<{
    staff: StaffOption[];
    shifts: StaffShiftListItem[];
    filters: Filters;
}>();

const selectedStaffId = ref<number | null>(props.filters.staff_id);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const editingShiftId = ref<number | null>(null);

const form = useForm({
    staff_id: props.filters.staff_id ?? props.staff[0]?.user_id ?? null,
    work_date: props.filters.from,
    start_at: '09:00',
    end_at: '18:00',
});

const formatDate = (date: Date): string => date.toISOString().slice(0, 10);

const addDays = (value: string, days: number): string => {
    const date = new Date(`${value}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return formatDate(date);
};

const applyFilters = (): void => {
    router.get(
        '/admin/staff-shifts',
        {
            staff_id: selectedStaffId.value ?? undefined,
            from: from.value,
            to: to.value,
        },
        { preserveState: true, replace: true },
    );
};

const moveWeek = (days: number): void => {
    from.value = addDays(from.value, days);
    to.value = addDays(to.value, days);
    applyFilters();
};

const resetForm = (): void => {
    editingShiftId.value = null;
    form.clearErrors();
    form.staff_id = props.filters.staff_id ?? props.staff[0]?.user_id ?? null;
    form.work_date = props.filters.from;
    form.start_at = '09:00';
    form.end_at = '18:00';
};

const submit = (): void => {
    if (editingShiftId.value === null) {
        form.post('/admin/staff-shifts', {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        });

        return;
    }

    form.put(`/admin/staff-shifts/${editingShiftId.value}`, {
        preserveScroll: true,
        onSuccess: () => resetForm(),
    });
};

const editShift = (shift: StaffShiftListItem): void => {
    editingShiftId.value = shift.id;
    form.clearErrors();
    form.staff_id = shift.staff_id;
    form.work_date = shift.work_date;
    form.start_at = shift.start_at;
    form.end_at = shift.end_at;
};

const deleteShift = (shift: StaffShiftListItem): void => {
    if (!window.confirm(`${shift.staff_display_name}の勤務枠を削除しますか？`)) {
        return;
    }

    router.delete(`/admin/staff-shifts/${shift.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="勤務枠" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">勤務枠</h1>
        <v-btn variant="text" href="/admin/staff">スタッフ一覧へ</v-btn>
    </div>

    <v-card class="mb-6" title="表示期間（週）">
        <v-card-text>
            <v-form class="d-flex align-center ga-3 flex-wrap" @submit.prevent="applyFilters">
                <v-select
                    v-model="selectedStaffId"
                    label="スタッフ"
                    :items="staff"
                    item-title="display_name"
                    item-value="user_id"
                    clearable
                    hide-details
                    min-width="220"
                />
                <v-text-field v-model="from" label="開始日" type="date" hide-details />
                <v-text-field v-model="to" label="終了日" type="date" hide-details />
                <v-btn type="submit" variant="tonal">表示</v-btn>
                <v-btn variant="text" @click="moveWeek(-7)">前週</v-btn>
                <v-btn variant="text" @click="moveWeek(7)">翌週</v-btn>
            </v-form>
        </v-card-text>
    </v-card>

    <v-row>
        <v-col cols="12" lg="4">
            <v-card :title="editingShiftId === null ? '勤務枠を追加' : '勤務枠を編集'">
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-select
                            v-model="form.staff_id"
                            label="スタッフ"
                            :items="staff"
                            item-title="display_name"
                            item-value="user_id"
                            :disabled="editingShiftId !== null"
                            :error-messages="form.errors.staff_id"
                            required
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item
                                    v-bind="itemProps"
                                    :subtitle="item.raw.is_bookable ? undefined : '予約受付停止中'"
                                />
                            </template>
                        </v-select>
                        <v-text-field
                            v-model="form.work_date"
                            label="勤務日"
                            type="date"
                            :error-messages="form.errors.work_date"
                            required
                        />
                        <div class="d-flex ga-3">
                            <v-text-field
                                v-model="form.start_at"
                                label="開始"
                                type="time"
                                :error-messages="form.errors.start_at"
                                required
                            />
                            <v-text-field
                                v-model="form.end_at"
                                label="終了"
                                type="time"
                                :error-messages="form.errors.end_at"
                                required
                            />
                        </div>
                        <div class="d-flex ga-3">
                            <v-btn type="submit" color="primary" :loading="form.processing">
                                {{ editingShiftId === null ? '追加' : '更新' }}
                            </v-btn>
                            <v-btn
                                v-if="editingShiftId !== null"
                                variant="text"
                                @click="resetForm"
                            >
                                編集を取消
                            </v-btn>
                        </div>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" lg="8">
            <v-card title="勤務枠一覧">
                <v-table>
                    <thead>
                        <tr>
                            <th>日付</th>
                            <th>スタッフ</th>
                            <th>開始</th>
                            <th>終了</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in shifts" :key="shift.id">
                            <td>{{ shift.work_date }}</td>
                            <td>{{ shift.staff_display_name }}</td>
                            <td>{{ shift.start_at }}</td>
                            <td>{{ shift.end_at }}</td>
                            <td class="text-no-wrap">
                                <v-btn size="small" variant="text" @click="editShift(shift)">
                                    編集
                                </v-btn>
                                <v-btn
                                    size="small"
                                    variant="text"
                                    color="error"
                                    @click="deleteShift(shift)"
                                >
                                    削除
                                </v-btn>
                            </td>
                        </tr>
                        <tr v-if="shifts.length === 0">
                            <td colspan="5" class="text-center text-medium-emphasis py-8">
                                この期間の勤務枠はありません。
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card>
        </v-col>
    </v-row>
</template>
