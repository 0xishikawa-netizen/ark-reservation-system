<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PanelShell from '@/components/admin/PanelShell.vue';
import { DateField, TimeField } from '@/components/ark';
import { applyBlockPrefill, blockEndTimeFrom, type BlockDraft } from '@/composables/reservationDraft';
import { isPastDateTime } from '@/utils/pastDateTime';
import { MESSAGES } from '@/constants/messages';

interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

interface BusinessHours {
    open: string;
    close: string;
    slot_minutes: number;
}

export interface BlockCreatePrefill {
    staff_id: number | null;
    booth_id: number | null;
    date: string | null;
    time: string | null;
}

// よく使う4種はチップで大きく、残りは小さめの補助チップにする（§32・312px内に収まるよう短い表記）。
const MAIN_BLOCK_TYPES = [
    { value: 'BREAK', title: '休憩' },
    { value: 'MEETING', title: 'ミーティング' },
    { value: 'ADMIN', title: '事務' },
    { value: 'CLEANING', title: '清掃' },
];
const SUB_BLOCK_TYPES = [
    { value: 'TRAINING', title: '研修' },
    { value: 'OUT', title: '外出' },
    { value: 'OTHER', title: 'その他' },
];

const props = withDefaults(defineProps<{
    staff: StaffOption[];
    /** 予定を置けるのは台帳に出ている営業時間内だけ（§時間の選択肢を営業時間に限定）。 */
    businessHours: BusinessHours;
    prefill: BlockCreatePrefill;
    /** 入力中身の下書き。Schedule/Index.vue が持ち続けるオブジェクトをそのまま受け取り、
     * ここでの入力をこのオブジェクトへ直接書き戻す（パネル切替・戻るをまたいで復元するため・§1）。 */
    draft: BlockDraft;
    returnQuery?: Record<string, string | number | undefined>;
    canGoBack?: boolean;
    /** 作成成功時の処理。アンマウント後も呼べるよう emit ではなく関数で受け取る（NewReservationPanel と同じ理由）。 */
    afterCreate?: () => void;
}>(), {
    canGoBack: false,
    afterCreate: undefined,
});

const emit = defineEmits<{
    close: [];
    back: [];
    switchToReservation: [];
}>();

// URL 経由の prefill（空き枠クリックなど「新しく分かった具体的な情報」）だけを
// draft へ上書きする。値が null の項目は draft の既存値をそのまま残す。
applyBlockPrefill(props.draft, {
    staff_id: props.prefill.staff_id,
    booth_id: props.prefill.booth_id,
    date: props.prefill.date,
    time: props.prefill.time,
});

function fmtDay(iso: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return '';
    }

    const [y, m, d] = iso.split('-').map(Number);
    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(new Date(y, m - 1, d));

    return `${m}/${d}（${weekday}）`;
}

function todayIso(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

// 予定は「スタッフの予定」だけを扱う（ブース単位の予定は業務上使わない・§ブース廃止）。
const form = useForm({
    staff_id: props.draft.staff_id,
    booth_id: null as number | null,
    work_date: props.draft.work_date || todayIso(),
    start_at: props.draft.start_at || props.businessHours.open.slice(0, 5),
    end_at: props.draft.end_at || blockEndTimeFrom(props.draft.start_at || props.businessHours.open.slice(0, 5)),
    type: props.draft.type,
    title: props.draft.title,
    note: props.draft.note,
});

// 入力値をそのまま draft へ書き戻す（切替・戻るで復元できるように）。
watch(() => form.data(), () => {
    props.draft.target_kind = 'staff';
    props.draft.staff_id = form.staff_id;
    props.draft.booth_id = null;
    props.draft.work_date = form.work_date;
    props.draft.start_at = form.start_at;
    props.draft.end_at = form.end_at;
    props.draft.type = form.type;
    props.draft.title = form.title;
    props.draft.note = form.note;
}, { deep: true });

// 開始を動かしたら、終了はその後ろへ自動で追従させる（終了 < 開始 を作らせない）。
watch(() => form.start_at, (start, prev) => {
    if (!start || start === prev) {
        return;
    }
    if (form.end_at === '' || form.end_at <= start) {
        form.end_at = blockEndTimeFrom(start);
    }
});

const requiresTitle = computed(() => form.type === 'OTHER');

/** 台帳に出ている営業時間の範囲（TimeField の選択肢をこの中だけに絞る）。 */
const openTime = computed(() => props.businessHours.open.slice(0, 5));
const closeTime = computed(() => props.businessHours.close.slice(0, 5));
const stepMinutes = computed(() => Math.max(props.businessHours.slot_minutes, 5));
/** 終了時刻は開始より後だけを選べるようにする。 */
const endMinTime = computed(() => {
    if (form.start_at === '') {
        return openTime.value;
    }

    const [h, m] = form.start_at.split(':').map(Number);
    const next = h * 60 + m + stepMinutes.value;

    return `${String(Math.floor(next / 60)).padStart(2, '0')}:${String(next % 60).padStart(2, '0')}`;
});

const selectedStaffName = computed<string | null>(
    () => props.staff.find((s) => s.user_id === form.staff_id)?.display_name ?? null,
);

function submitUrl(path: string): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(props.returnQuery ?? {})) {
        if (value !== undefined) {
            params.set(key, String(value));
        }
    }

    const query = params.toString();

    return query === '' ? path : `${path}?${query}`;
}

const pastConfirmOpen = ref(false);
const pastConfirmLabel = computed(() => {
    const value = form.work_date && form.start_at ? `${form.work_date} ${form.start_at}` : null;

    return value ? value.slice(0, 16).replace(/-/g, '/') : '';
});

/** 作成ボタン。過去の日時なら確認ダイアログを挟む。 */
function requestSubmit(): void {
    const value = form.work_date && form.start_at ? `${form.work_date} ${form.start_at}` : null;
    if (isPastDateTime(value)) {
        pastConfirmOpen.value = true;

        return;
    }
    submit();
}

function submit(): void {
    const afterCreate = props.afterCreate;
    form.post(submitUrl('/admin/schedule/blocks'), {
        errorBag: 'reservation',
        onSuccess: () => afterCreate?.(),
    });
}
</script>

<template>
    <PanelShell
        title="予定を追加"
        icon="mdi-clock-plus-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <!-- 予約パネルと同じ「日付／時間／担当」のカード。 -->
        <div class="sbc__when">
            <div class="sbc__when-cell">
                <span class="sbc__when-label">日付</span>
                <span class="sbc__when-value">{{ fmtDay(form.work_date) }}</span>
            </div>
            <div class="sbc__when-cell">
                <span class="sbc__when-label">時間</span>
                <span class="sbc__when-value sbc__when-value--time">{{ form.start_at }}〜{{ form.end_at }}</span>
            </div>
            <div v-if="selectedStaffName" class="sbc__when-cell sbc__when-cell--staff">
                <span class="sbc__when-label">担当</span>
                <span class="sbc__when-value">{{ selectedStaffName }}</span>
            </div>
        </div>

        <v-select
            v-model="form.staff_id"
            :items="staff"
            item-title="display_name"
            item-value="user_id"
            label="スタッフ"
            density="compact"
            variant="outlined"
            hide-details="auto"
            :error-messages="form.errors.staff_id"
        />

        <div class="sbc__typefield">
            <span class="sbc__typelabel">種類</span>
            <div class="sbc__typechips" role="radiogroup" aria-label="予定の種類">
                <v-chip
                    v-for="t in MAIN_BLOCK_TYPES"
                    :key="t.value"
                    :color="form.type === t.value ? 'primary' : undefined"
                    :variant="form.type === t.value ? 'flat' : 'outlined'"
                    size="small"
                    role="radio"
                    :aria-checked="form.type === t.value"
                    @click="form.type = t.value"
                >
                    {{ t.title }}
                </v-chip>
            </div>
            <div class="sbc__typechips sbc__typechips--sub">
                <v-chip
                    v-for="t in SUB_BLOCK_TYPES"
                    :key="t.value"
                    size="small"
                    variant="text"
                    :class="{ 'sbc__typechip--active': form.type === t.value }"
                    role="radio"
                    :aria-checked="form.type === t.value"
                    @click="form.type = t.value"
                >
                    {{ t.title }}
                </v-chip>
            </div>
            <p v-if="form.errors.type" class="sbc__error">{{ form.errors.type }}</p>
        </div>

        <v-text-field
            v-if="requiresTitle"
            v-model="form.title"
            label="タイトル"
            placeholder="例：撮影、銀行、機器メンテナンス"
            density="compact"
            variant="outlined"
            hide-details="auto"
            :error-messages="form.errors.title"
        />

        <DateField v-model="form.work_date" label="日付" density="compact" :clearable="false" />

        <div class="sbc__time-row">
            <TimeField
                v-model="form.start_at"
                label="開始"
                density="compact"
                :min-time="openTime"
                :max-time="closeTime"
                :step-minutes="stepMinutes"
                :error-messages="form.errors.start_at"
            />
            <TimeField
                v-model="form.end_at"
                label="終了"
                density="compact"
                :min-time="endMinTime"
                :max-time="closeTime"
                :step-minutes="stepMinutes"
                :error-messages="form.errors.end_at"
            />
        </div>

        <v-textarea
            v-model="form.note"
            label="メモ（任意）"
            rows="2"
            auto-grow
            variant="outlined"
            density="compact"
            hide-details="auto"
            :error-messages="form.errors.note"
        />

        <!-- 予約へ切り替える。予約パネルの「スタッフ予定を追加」と同じ控えめな補助操作の形にする。 -->
        <div class="sbc__secondary">
            <v-btn variant="text" size="x-small" prepend-icon="mdi-calendar-plus-outline" data-testid="switch-to-reservation" @click="emit('switchToReservation')">
                {{ MESSAGES.schedule.switchToReservation }}
            </v-btn>
        </div>

        <template #footer>
            <v-btn
                color="primary"
                variant="flat"
                size="small"
                block
                :disabled="form.work_date === '' || form.start_at === '' || form.end_at === ''"
                :loading="form.processing"
                @click="requestSubmit"
            >
                追加する
            </v-btn>
        </template>
        <!-- 過去の日時に入れる時だけ確認する（入力ミス防止）。 -->
        <v-dialog v-model="pastConfirmOpen" max-width="400">
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">{{ MESSAGES.schedule.pastConfirmTitle }}</v-card-title>
                <v-card-text>{{ MESSAGES.schedule.pastConfirmBody.replace('{when}', pastConfirmLabel) }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="pastConfirmOpen = false">やめる</v-btn>
                    <v-btn color="primary" variant="flat" data-testid="past-confirm" @click="pastConfirmOpen = false; submit()">この日時で登録する</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </PanelShell>
</template>

<style scoped>
.sbc__secondary {
    display: flex;
    justify-content: flex-end;
    margin-top: -4px;
}

.sbc__when {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: var(--ark-space-3);
    padding: var(--ark-space-3);
    background: rgba(var(--v-theme-primary), 0.05);
    border: 1px solid rgba(var(--v-theme-primary), 0.14);
    border-radius: 10px;
}

.sbc__when-cell {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
}

.sbc__when-cell--staff {
    grid-column: 1 / -1;
    padding-top: var(--ark-space-2);
    border-top: 1px dashed rgba(var(--v-theme-primary), 0.2);
}

.sbc__when-label {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.62);
}

.sbc__when-value {
    font-size: 0.8125rem;
    font-weight: 700;
    line-height: 1.3;
    word-break: break-word;
}

.sbc__when-value--time {
    font-size: 1rem;
    font-weight: 800;
    color: rgb(var(--v-theme-primary));
    font-variant-numeric: tabular-nums;
}

.sbc__typefield {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sbc__typelabel {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.sbc__typechips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.sbc__typechips--sub :deep(.v-chip) {
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.sbc__typechip--active {
    font-weight: 800;
    color: rgb(var(--v-theme-primary)) !important;
}

.sbc__error {
    margin: 0;
    font-size: 0.6875rem;
    color: rgb(var(--v-theme-error));
}

/* 「代わりに予約を入れる」は、下線リンクではなく他の入力欄と同じ幅の控えめなボタンにする。 */



.sbc__time-row {
    display: flex;
    gap: var(--ark-space-3);
}

.sbc__time-row > * {
    flex: 1 1 0;
    min-width: 0;
}
</style>
