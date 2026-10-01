<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PanelShell from '@/components/admin/PanelShell.vue';
import { DateField, TimeField } from '@/components/ark';
import { LEGACY_BLOCK_TYPES, BLOCK_TYPES } from '@/constants/scheduleBlockTypes';
import { MESSAGES } from '@/constants/messages';

interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

interface BoothOption {
    id: number;
    name: string;
}

interface ScheduleBlock {
    id: number;
    staff_id: number | null;
    booth_id: number | null;
    date: string;
    start_at: string;
    end_at: string;
    type: string;
    type_label: string;
    title: string | null;
    note: string | null;
}

/** 選べる種類。以前の種類（事務・清掃）の予定を開いた時は、その種類も並べる。 */
const blockTypeOptions = computed(() => (BLOCK_TYPES.some((t) => t.value === form.type) ? BLOCK_TYPES : [...BLOCK_TYPES, ...LEGACY_BLOCK_TYPES.filter((t) => t.value === form.type)]));

interface BusinessHours {
    open: string;
    close: string;
    slot_minutes: number;
}

const props = withDefaults(defineProps<{
    block: ScheduleBlock;
    staff: StaffOption[];
    booths: BoothOption[];
    /** 予定を置けるのは台帳に出ている営業時間内だけ（§時間の選択肢を営業時間に限定）。 */
    businessHours: BusinessHours;
    returnQuery?: Record<string, string | number | undefined>;
    canGoBack?: boolean;
}>(), {
    canGoBack: false,
});

/** 種類ごとのアイコン・色は台帳のブロック表示と揃える（同じものだと一目で分かるように）。 */
const BLOCK_ICON: Record<string, string> = {
    BREAK: 'mdi-coffee-outline',
    MEETING: 'mdi-account-group-outline',
    ADMIN: 'mdi-file-document-outline',
    CLEANING: 'mdi-broom',
    WORK: 'mdi-briefcase-outline',
    TRAINING: 'mdi-school-outline',
    OUT: 'mdi-walk',
    OTHER: 'mdi-dots-horizontal',
};

const BLOCK_COLOR: Record<string, string> = {
    BREAK: 'warning',
    MEETING: 'info',
    ADMIN: 'secondary',
    CLEANING: 'success',
    WORK: 'primary',
    TRAINING: 'accent',
    OUT: 'secondary',
    OTHER: 'secondary',
};

const blockIcon = computed(() => BLOCK_ICON[props.block.type] ?? 'mdi-calendar-blank-outline');
const blockColor = computed(() => BLOCK_COLOR[props.block.type] ?? 'secondary');

function toMinutes(hhmm: string): number {
    const [h, m] = hhmm.slice(0, 5).split(':').map(Number);

    return h * 60 + m;
}

/** 所要時間（「1時間30分」の形）。何分押さえているかを一目で分かるようにする。 */
const durationLabel = computed<string>(() => {
    const minutes = Math.max(toMinutes(props.block.end_at) - toMinutes(props.block.start_at), 0);
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}分`;
    }

    return rest === 0 ? `${hours}時間` : `${hours}時間${rest}分`;
});

function fmtDay(iso: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return iso;
    }

    const [y, m, d] = iso.split('-').map(Number);
    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(new Date(y, m - 1, d));

    return `${y}/${String(m).padStart(2, '0')}/${String(d).padStart(2, '0')}（${weekday}）`;
}

const openTime = computed(() => props.businessHours.open.slice(0, 5));
const closeTime = computed(() => props.businessHours.close.slice(0, 5));
const stepMinutes = computed(() => Math.max(props.businessHours.slot_minutes, 5));

const emit = defineEmits<{
    close: [];
    back: [];
    deleted: [];
}>();

const editing = ref(false);
const deleteConfirm = ref(false);
const deleting = ref(false);

const targetName = computed<string>(() => {
    if (props.block.staff_id !== null) {
        return props.staff.find((s) => s.user_id === props.block.staff_id)?.display_name ?? 'スタッフ';
    }
    if (props.block.booth_id !== null) {
        return props.booths.find((b) => b.id === props.block.booth_id)?.name ?? 'ブース';
    }

    return MESSAGES.common.emptyValue;
});

function buildForm() {
    return useForm({
        staff_id: props.block.staff_id,
        booth_id: props.block.booth_id,
        work_date: props.block.date,
        start_at: props.block.start_at,
        end_at: props.block.end_at,
        type: props.block.type,
        title: props.block.title ?? '',
        note: props.block.note ?? '',
    });
}

let form = buildForm();

watch(() => props.block.id, () => {
    editing.value = false;
    form = buildForm();
});

const requiresTitle = computed(() => form.type === 'OTHER');

function withReturnQuery(path: string): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(props.returnQuery ?? {})) {
        if (value !== undefined) {
            params.set(key, String(value));
        }
    }

    const query = params.toString();

    return query === '' ? path : `${path}?${query}`;
}

function submitEdit(): void {
    form.put(withReturnQuery(`/admin/schedule/blocks/${props.block.id}`), {
        errorBag: 'reservation',
        onSuccess: () => { editing.value = false; },
    });
}

function confirmDelete(): void {
    deleting.value = true;
    router.delete(withReturnQuery(`/admin/schedule/blocks/${props.block.id}`), {
        onSuccess: () => emit('deleted'),
        onFinish: () => { deleting.value = false; deleteConfirm.value = false; },
    });
}
</script>

<template>
    <PanelShell
        title="予定詳細"
        icon="mdi-clock-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <template v-if="!editing">
            <!-- 見出し：種類のアイコン＋名前を大きく、補足のタイトルはその下に。 -->
            <div class="sbd__hero" :class="`sbd__hero--${blockColor}`">
                <span class="sbd__hero-icon">
                    <v-icon :icon="blockIcon" size="22" />
                </span>
                <span class="sbd__hero-body">
                    <span class="sbd__hero-type">{{ block.type_label }}</span>
                    <span v-if="block.title" class="sbd__hero-title">{{ block.title }}</span>
                </span>
                <span class="sbd__hero-duration">{{ durationLabel }}</span>
            </div>

            <!-- 時間帯：開始〜終了を数字で大きく見せる。 -->
            <div class="sbd__timebox">
                <span class="sbd__timebox-date">{{ fmtDay(block.date) }}</span>
                <span class="sbd__timebox-range">
                    <span class="sbd__timebox-time">{{ block.start_at.slice(0, 5) }}</span>
                    <v-icon icon="mdi-arrow-right" size="14" class="sbd__timebox-arrow" />
                    <span class="sbd__timebox-time">{{ block.end_at.slice(0, 5) }}</span>
                </span>
            </div>

            <!-- 対象・メモは定義リストで揃える。 -->
            <dl class="sbd__facts">
                <div>
                    <dt>対象</dt>
                    <dd>
                        <v-icon icon="mdi-account-outline" size="13" class="sbd__facts-icon" />
                        {{ targetName }}
                    </dd>
                </div>
                <div>
                    <dt>メモ</dt>
                    <dd :class="{ 'sbd__facts-empty': !block.note }">
                        {{ block.note || MESSAGES.common.emptyValue }}
                    </dd>
                </div>
            </dl>

            <p class="sbd__lead">
                {{ MESSAGES.schedule.blockNotBookable }}
            </p>
        </template>

        <template v-else>
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

            <div class="sbd__typefield">
                <span class="sbd__typelabel">種類</span>
                <div class="sbd__typegrid" role="radiogroup" aria-label="予定の種類">
                    <button
                        v-for="t in blockTypeOptions"
                        :key="t.value"
                        type="button"
                        class="sbd__type"
                        :class="{ 'sbd__type--active': form.type === t.value }"
                        role="radio"
                        :aria-checked="form.type === t.value"
                        @click="form.type = t.value"
                    >
                        {{ t.title }}
                    </button>
                </div>
                <p v-if="form.errors.type" class="sbd__error">{{ form.errors.type }}</p>
            </div>

            <v-text-field
                v-if="requiresTitle"
                v-model="form.title"
                label="タイトル"
                density="compact"
                variant="outlined"
                hide-details="auto"
                :error-messages="form.errors.title"
            />

            <DateField block v-model="form.work_date" label="日付" density="compact" :clearable="false" />

            <div class="sbd__time-row">
                <TimeField block
                    v-model="form.start_at"
                    label="開始"
                    density="compact"
                    :min-time="openTime"
                    :max-time="closeTime"
                    :step-minutes="stepMinutes"
                    :error-messages="form.errors.start_at"
                />
                <TimeField block
                    v-model="form.end_at"
                    label="終了"
                    density="compact"
                    :min-time="openTime"
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
        </template>

        <template #footer>
            <div v-if="!editing" class="sbd__actions">
                <v-btn variant="outlined" color="accent" size="small" prepend-icon="mdi-pencil-outline" @click="editing = true">
                    編集
                </v-btn>
                <v-btn variant="outlined" color="error" size="small" prepend-icon="mdi-delete-outline" @click="deleteConfirm = true">
                    削除
                </v-btn>
            </div>
            <div v-else class="sbd__actions">
                <v-btn variant="text" size="small" :disabled="form.processing" @click="editing = false">やめる</v-btn>
                <v-btn color="primary" variant="flat" size="small" :loading="form.processing" @click="submitEdit">
                    変更を保存
                </v-btn>
            </div>
        </template>

        <v-dialog v-model="deleteConfirm" max-width="360">
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">{{ MESSAGES.schedule.confirmBlockDelete }}</v-card-title>
                <v-card-text>
                    {{ block.type_label }}（{{ block.date }} {{ block.start_at }}〜{{ block.end_at }}） {{ targetName }}
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="deleting" @click="deleteConfirm = false">キャンセル</v-btn>
                    <v-btn color="error" variant="flat" :loading="deleting" @click="confirmDelete">削除する</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </PanelShell>
</template>

<style scoped>
/* 見出し：種類の色をそのまま帯にして、台帳上のブロックと同じ色で認識できるようにする。 */
.sbd__hero {
    display: flex;
    align-items: center;
    gap: var(--ark-space-3);
    padding: var(--ark-space-3);
    border-radius: var(--ark-radius);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
    background: rgba(var(--v-theme-on-surface), 0.04);
}

.sbd__hero-icon {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 999px;
    background: rgb(var(--v-theme-surface));
    color: rgba(var(--v-theme-on-surface), 0.78);
}

.sbd__hero--warning {
    border-color: rgba(var(--v-theme-warning), 0.35);
    background: rgba(var(--v-theme-warning), 0.1);
}

.sbd__hero--warning .sbd__hero-icon {
    color: rgb(var(--v-theme-warning));
}

.sbd__hero--info {
    border-color: rgba(var(--v-theme-info), 0.35);
    background: rgba(var(--v-theme-info), 0.1);
}

.sbd__hero--info .sbd__hero-icon {
    color: rgb(var(--v-theme-info));
}

.sbd__hero--success {
    border-color: rgba(var(--v-theme-success), 0.35);
    background: rgba(var(--v-theme-success), 0.1);
}

.sbd__hero--success .sbd__hero-icon {
    color: rgb(var(--v-theme-success));
}

.sbd__hero--accent {
    border-color: rgba(var(--v-theme-accent), 0.35);
    background: rgba(var(--v-theme-accent), 0.1);
}

.sbd__hero--accent .sbd__hero-icon {
    color: rgb(var(--v-theme-accent));
}

.sbd__hero--secondary {
    border-color: rgba(var(--v-theme-secondary), 0.35);
    background: rgba(var(--v-theme-secondary), 0.1);
}

.sbd__hero--secondary .sbd__hero-icon {
    color: rgb(var(--v-theme-secondary));
}

.sbd__hero-body {
    display: flex;
    flex: 1 1 auto;
    min-width: 0;
    flex-direction: column;
    gap: 1px;
}

.sbd__hero-type {
    font-size: 0.9375rem;
    font-weight: 800;
}

.sbd__hero-title {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.76);
    word-break: break-word;
}

.sbd__hero-duration {
    flex: 0 0 auto;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgb(var(--v-theme-surface));
    font-size: 0.625rem;
    font-weight: 800;
    color: rgba(var(--v-theme-on-surface), 0.78);
}

/* 時間帯：開始→終了を数字で読ませる。 */
.sbd__timebox {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--ark-space-3);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-primary), 0.06);
    border: 1px solid rgba(var(--v-theme-primary), 0.14);
}

.sbd__timebox-date {
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.76);
}

.sbd__timebox-range {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
}

.sbd__timebox-time {
    font-size: 1.25rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: rgb(var(--v-theme-primary));
}

.sbd__timebox-arrow {
    color: rgba(var(--v-theme-primary), 0.7);
}

.sbd__facts {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-2);
    margin: 0;
}

.sbd__facts > div {
    display: flex;
    flex-direction: column;
    gap: 1px;
    padding-bottom: var(--ark-space-2);
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.sbd__facts > div:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.sbd__facts dt {
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.sbd__facts dd {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

.sbd__facts-icon {
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.sbd__facts-empty {
    color: rgba(var(--v-theme-on-surface), 0.5);
}

.sbd__lead {
    margin: 0;
    font-size: 0.625rem;
    line-height: 1.6;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.sbd__typefield {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sbd__typelabel {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.sbd__typegrid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 6px;
}

.sbd__type {
    height: 32px;
    padding: 0 2px;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
    border-radius: 8px;
    background: rgb(var(--v-theme-surface));
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.75);
    white-space: nowrap;
    cursor: pointer;
}

.sbd__type:hover {
    border-color: rgb(var(--v-theme-primary));
}

.sbd__type--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgb(var(--v-theme-primary));
    color: #fff;
}

.sbd__error {
    margin: 0;
    font-size: 0.6875rem;
    color: rgb(var(--v-theme-error));
}

.sbd__actions {
    display: flex;
    gap: var(--ark-space-2);
}

.sbd__actions .v-btn {
    flex: 1 1 0;
}

.sbd__time-row {
    display: flex;
    gap: var(--ark-space-3);
}

.sbd__time-row > * {
    flex: 1 1 0;
    min-width: 0;
}
</style>
