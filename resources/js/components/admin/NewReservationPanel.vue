<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import PanelShell from '@/components/admin/PanelShell.vue';
import MenuPicker from '@/components/admin/MenuPicker.vue';
import { DateField } from '@/components/ark';
import { applyReservationPrefill, type ReservationDraft } from '@/composables/reservationDraft';
import { MESSAGES, unavailableDesiredTimeMessage } from '@/constants/messages';

interface CustomerOption {
    user_id: number;
    name: string;
    kana: string | null;
}

interface ServiceOption {
    id: number;
    name: string;
    duration_min: number;
    requires_staff: boolean;
    staff_ids: number[];
    /** メニューで使える具体ブース（空＝全ブース。Task 11-28）。 */
    booth_ids?: number[];
    price: number;
    category: string | null;
    color: string;
}

interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

interface BoothOption {
    id: number;
    name: string;
}

interface AvailabilitySlot {
    starts_at: string;
    ends_at: string;
    available_staff_ids: number[];
}

export interface CreatePrefill {
    customer_id: number | null;
    service_id: number | null;
    staff_id: number | null;
    booth_id: number | null;
    date: string | null;
    time: string | null;
}

const props = withDefaults(defineProps<{
    services: ServiceOption[];
    /** 店舗全体・直近30日の実績から算出した「よく使うメニュー」のID（MenuPicker用・§7）。 */
    popularServiceIds?: number[];
    staff: StaffOption[];
    booths: BoothOption[];
    prefill: CreatePrefill;
    /** 入力中身の下書き。Schedule/Index.vue が持ち続けるオブジェクトをそのまま受け取り、
     * ここでの入力をこのオブジェクトへ直接書き戻す（パネル切替・戻るをまたいで復元するため・§1）。 */
    draft: ReservationDraft;
    /** 作成後、台帳の軸・スタッフ絞り込みをリセットせず今の表示状態へ戻すためのクエリ。 */
    returnQuery?: Record<string, string | number | undefined>;
    canGoBack?: boolean;
}>(), {
    canGoBack: false,
    popularServiceIds: () => [],
});

const emit = defineEmits<{
    close: [];
    back: [];
    created: [payload: { date: string }];
    switchToBlock: [];
}>();

const dateTimeLocked = computed(
    () => props.prefill.date !== null && props.prefill.time !== null,
);
const awaitingBoardSlotSelection = computed(
    () => props.prefill.customer_id !== null && props.prefill.date === null,
);


// URL 経由の prefill（空き枠クリック・再予約など「新しく分かった具体的な情報」）だけを
// draft へ上書きする。値が null の項目は draft の既存値をそのまま残す＝パネル切替や
// 戻るで入力済みの内容を失わない。
applyReservationPrefill(props.draft, {
    customer_id: props.prefill.customer_id,
    service_id: props.prefill.service_id,
    staff_id: props.prefill.staff_id,
    booth_id: props.prefill.booth_id,
    date: props.prefill.date,
    time: props.prefill.time,
});

/** 顧客は「パネル上部の常時表示の検索欄」で選ぶ。ここでは選ばれた顧客の表示名だけ持つ。 */
const customerItems = ref<CustomerOption[]>([]);

function todayIso(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

const selectedServiceId = ref<number | null>(props.draft.service_id);
const selectedStaffId = ref<number | null>(props.draft.staff_id);
const selectedBoothId = ref<number | null>(props.draft.booth_id);
// 日付は未指定なら今日にしておく（毎回手で選ばせない）。
const date = ref(props.draft.date || todayIso());
const slots = ref<AvailabilitySlot[]>([]);
const loadingSlots = ref(false);
const availabilityLoaded = ref(false);
/**
 * 「本当はこの時刻にしたい」という希望時刻。空き枠クリックで渡された時刻や、
 * ユーザーが選んだ時刻をここに覚えておく。form.starts_at は空き時間の取り直しの
 * たびに一度クリアされるため、それとは別に保持しないと希望時刻が消えてしまう。
 */
const desiredStartsAt = ref<string | null>(props.draft.starts_at);

const BUFFER_OPTIONS = [
    { value: 0, label: 'なし' },
    { value: 5, label: '5分' },
    { value: 10, label: '10分' },
    { value: 15, label: '15分' },
];

const form = useForm({
    customer_id: props.draft.customer_id,
    service_id: props.draft.service_id,
    staff_id: props.draft.staff_id,
    is_staff_requested: props.draft.is_staff_requested,
    booth_id: props.draft.booth_id,
    starts_at: props.draft.starts_at,
    buffer_min: props.draft.buffer_min,
    notes: props.draft.notes,
});

// 顧客欄の表示名。draft に既にキャッシュがあれば再取得しない（切替・戻るで毎回
// APIを叩かないため）。なければ1回だけ取得してキャッシュする。
if (props.draft.customer_id !== null) {
    if (props.draft.customer_name !== null) {
        customerItems.value = [{
            user_id: props.draft.customer_id,
            name: props.draft.customer_name,
            kana: props.draft.customer_kana,
        }];
    } else {
        customerItems.value = [{ user_id: props.draft.customer_id, name: MESSAGES.common.loadingInParens, kana: null }];

        void fetch(`/admin/customers/${props.draft.customer_id}/summary`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => (res.ok ? res.json() : null))
            .then((json: { profile?: { name?: string } } | null) => {
                if (json?.profile?.name && props.draft.customer_id !== null) {
                    props.draft.customer_name = json.profile.name;
                    customerItems.value = [{
                        user_id: props.draft.customer_id,
                        name: json.profile.name,
                        kana: null,
                    }];
                }
            })
            .catch(() => undefined);
    }
}

// 入力値をそのまま draft へ書き戻す（切替・戻るで復元できるように）。
watch(
    [selectedServiceId, selectedStaffId, selectedBoothId, date, () => form.data()],
    () => {
        props.draft.service_id = selectedServiceId.value;
        props.draft.staff_id = selectedStaffId.value;
        props.draft.booth_id = selectedBoothId.value;
        props.draft.date = date.value;
        props.draft.customer_id = form.customer_id;
        props.draft.is_staff_requested = form.is_staff_requested;
        props.draft.starts_at = form.starts_at;
        props.draft.buffer_min = form.buffer_min;
        props.draft.notes = form.notes;
    },
    { deep: true },
);

// 常時表示の顧客検索（パネル上部）で選ばれた顧客を、このフォームへ取り込む。
watch(() => props.draft.customer_id, (id) => {
    if (id === form.customer_id) {
        return;
    }

    form.customer_id = id;

    if (id !== null && props.draft.customer_name !== null) {
        customerItems.value = [{
            user_id: id,
            name: props.draft.customer_name,
            kana: props.draft.customer_kana,
        }];
    }
});

const selectedService = computed<ServiceOption | null>(
    () => props.services.find((service) => service.id === selectedServiceId.value) ?? null,
);

const menuPickerOpen = ref(false);

const selectedStaffName = computed<string | null>(
    () => props.staff.find((s) => s.user_id === selectedStaffId.value)?.display_name ?? null,
);

// ブースは常にメニュー(コース)・時間から自動判別する（§手動選択は廃止）。
const selectedBoothName = computed<string | null>(
    () => props.booths.find((b) => b.id === selectedBoothId.value)?.name ?? null,
);

function fmtDay(iso: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return '';
    }

    const [y, m, d] = iso.split('-').map(Number);
    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(new Date(y, m - 1, d));

    return `${m}/${d}（${weekday}）`;
}

const staffItems = computed<StaffOption[]>(() => {
    if (selectedService.value === null) {
        return props.staff;
    }

    return props.staff.filter((staff) => selectedService.value?.staff_ids.includes(staff.user_id));
});

const staffSelectItems = computed(() => [
    { title: '指名なし（自動割当）', value: null as number | null },
    ...staffItems.value.map((s) => ({ title: s.display_name, value: s.user_id })),
]);

let availabilityRequestId = 0;

function clearAvailability(): void {
    availabilityRequestId++;
    slots.value = [];
    form.starts_at = null;
    availabilityLoaded.value = false;
}

// ブースはメニュー選択・時間確定に応じて自動で提案する。ユーザーが一度でも自分で
// 選び直したら、それ以降は自動提案で上書きしない（任意でいつでも変更できる）。
const boothManuallySet = ref(props.draft.booth_manually_set);
/** 選択中メニューで使えるブース（紐付けが無いメニューは全ブース）。 */
const boothOptions = computed(() => {
    const allowed = selectedService.value?.booth_ids ?? [];
    return allowed.length === 0 ? props.booths : props.booths.filter((booth) => allowed.includes(booth.id));
});
let assigningBoothAutomatically = false;

watch(selectedServiceId, () => {
    form.service_id = selectedServiceId.value;
    boothManuallySet.value = false;
    props.draft.booth_manually_set = false;
    clearAvailability();
});
watch(selectedStaffId, () => {
    form.staff_id = selectedStaffId.value;

    if (selectedStaffId.value === null) {
        form.is_staff_requested = false;
    }

    clearAvailability();
});
watch(selectedBoothId, () => {
    form.booth_id = selectedBoothId.value;

    if (!assigningBoothAutomatically) {
        boothManuallySet.value = true;
        props.draft.booth_manually_set = true;
        clearAvailability();
    }
});
watch(date, clearAvailability);

/** メニューが決まっている時間帯について、空いている最初のブースを自動提案する。 */
async function autoAssignBooth(startsAt: string): Promise<void> {
    if (boothManuallySet.value || selectedServiceId.value === null) {
        return;
    }

    const serviceId = selectedServiceId.value;
    try {
        const params = new URLSearchParams({
            service_id: String(serviceId),
            starts_at: startsAt,
            buffer_min: String(form.buffer_min),
        });
        const response = await fetch(`/admin/reservations/available-booth?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const json = (await response.json()) as { booth_id: number | null };

        if (json.booth_id !== null && !boothManuallySet.value
            && selectedServiceId.value === serviceId && form.starts_at === startsAt) {
            assigningBoothAutomatically = true;
            selectedBoothId.value = json.booth_id;
            form.booth_id = json.booth_id;
            await nextTick();
            assigningBoothAutomatically = false;
        }
    } catch {
        /* 自動提案に失敗しても手動でブースを選べるので致命的ではない */
    }
}

/* ───── 電話予約などで未登録のお客様を、その場で仮登録する（§新規のお客様） ───── */
const provisionalOpen = ref(false);
const provisionalSaving = ref(false);
const provisionalError = ref<string | null>(null);
const provisional = ref({ name: '', kana: '', phone: '' });

function openProvisional(): void {
    provisional.value = { name: '', kana: '', phone: '' };
    provisionalError.value = null;
    provisionalOpen.value = true;
}

async function submitProvisional(): Promise<void> {
    const body = provisional.value;

    if ([body.name, body.kana, body.phone].every((v) => v.trim() === '')) {
        provisionalError.value = MESSAGES.reservation.provisionalCustomerRequired;

        return;
    }

    provisionalSaving.value = true;
    provisionalError.value = null;

    try {
        const response = await fetch('/admin/reservations/provisional-customer', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
                ),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            provisionalError.value = MESSAGES.reservation.provisionalCustomerInvalid;

            return;
        }

        const created = (await response.json()) as CustomerOption;
        customerItems.value = [created];
        form.customer_id = created.user_id;
        props.draft.customer_id = created.user_id;
        props.draft.customer_name = created.name;
        props.draft.customer_kana = created.kana;
        provisionalOpen.value = false;
    } catch {
        provisionalError.value = MESSAGES.reservation.provisionalCustomerNetwork;
    } finally {
        provisionalSaving.value = false;
    }
}

function clearCustomer(): void {
    form.customer_id = null;
    props.draft.customer_id = null;
    props.draft.customer_name = null;
    props.draft.customer_kana = null;
    customerItems.value = [];
}

/** 確定済み顧客の表示用（切替・戻るをまたいでも draft のキャッシュから復元される）。 */
const selectedCustomer = computed<CustomerOption | null>(() => {
    if (form.customer_id === null) {
        return null;
    }

    const known = customerItems.value.find((c) => c.user_id === form.customer_id);

    return known ?? {
        user_id: form.customer_id,
        name: props.draft.customer_name ?? MESSAGES.common.loadingInParens,
        kana: props.draft.customer_kana,
    };
});

async function loadAvailability(): Promise<void> {
    if (selectedServiceId.value === null || date.value === '') {
        return;
    }

    const requestId = ++availabilityRequestId;
    loadingSlots.value = true;
    availabilityLoaded.value = false;
    form.starts_at = null;

    const params = new URLSearchParams({
        service_id: String(selectedServiceId.value),
        date: date.value,
        buffer_min: String(form.buffer_min),
    });

    if (selectedStaffId.value !== null) {
        params.set('staff_id', String(selectedStaffId.value));
    }
    if (selectedBoothId.value !== null) {
        params.set('booth_id', String(selectedBoothId.value));
    }

    try {
        const response = await fetch(`/admin/reservations/availability?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const availableSlots = response.ok ? ((await response.json()) as AvailabilitySlot[]) : [];

        if (requestId !== availabilityRequestId) {
            return;
        }

        slots.value = availableSlots;
        availabilityLoaded.value = true;

        const match = desiredStartsAt.value === null
            ? undefined
            : slots.value.find((slot) => slot.starts_at === desiredStartsAt.value);

        if (match) {
            selectSlot(match);
        }
    } catch {
        if (requestId === availabilityRequestId) {
            slots.value = [];
        }
    } finally {
        if (requestId === availabilityRequestId) {
            loadingSlots.value = false;
        }
    }
}

function selectSlot(slot: AvailabilitySlot): void {
    desiredStartsAt.value = slot.starts_at;
    form.starts_at = slot.starts_at;
    form.staff_id = selectedStaffId.value ?? slot.available_staff_ids[0] ?? null;
    form.booth_id = selectedBoothId.value;
    void autoAssignBooth(slot.starts_at);
}

/**
 * メニュー・日付・担当・バッファのどれかが変わったら、空き時間を自動で取り直す。
 * 以前は「空き時間を確認」ボタンを押させていたが、何のためのボタンか分かりにくかったため
 * 明示操作なしで最新の候補が出るようにした（§空き時間の自動取得）。
 */
let availabilityTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    [selectedServiceId, selectedStaffId, date, () => form.buffer_min],
    () => {
        if (availabilityTimer !== null) {
            clearTimeout(availabilityTimer);
        }

        if (selectedServiceId.value === null || date.value === '') {
            slots.value = [];
            availabilityLoaded.value = false;

            return;
        }

        availabilityTimer = setTimeout(() => void loadAvailability(), 150);
    },
);

/**
 * 選択中の予約の終了時刻（施術時間だけ）。インターバルは予約の後ろの別区間なので含めない（Task 11-29）。
 * 例：14:00開始・60分・インターバル5分 → 予約 14:00〜15:00、ブロック 15:00〜15:05。
 */
const selectedEndLabel = computed<string | null>(() => {
    if (form.starts_at === null || selectedService.value === null) {
        return null;
    }

    const [h, m] = form.starts_at.slice(11, 16).split(':').map(Number);
    const total = h * 60 + m + selectedService.value.duration_min;

    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
});

// 空き枠クリックで開いた場合、または draft に前回選んだ時刻が残っている場合、
// その場で candidate slot を反映する（loadAvailability が form.starts_at を一旦
// クリアしてしまうため、先に目的の時刻を退避しておく）。
onMounted(() => {
    if (selectedServiceId.value !== null && date.value !== '') {
        void loadAvailability();
    }
});

function timeLabel(value: string): string {
    return value.slice(11, 16);
}

/** 空き枠クリックで指定された時刻が、このメニュー・担当では取れなかった時の表示用。 */
const unavailableDesiredTime = computed<string | null>(() => {
    if (
        !dateTimeLocked.value ||
        !availabilityLoaded.value ||
        loadingSlots.value ||
        form.starts_at !== null ||
        desiredStartsAt.value === null
    ) {
        return null;
    }

    return timeLabel(desiredStartsAt.value);
});

// SlotUnavailableException 等の競合エラーは 'reservation' バッグ・キーで返る
// （既存 Admin/Create.vue と同じサーバー契約）。フォームの型にはない動的キーのため個別に読む。
const reservationConflictError = computed<string | null>(
    () => (form.errors as Record<string, string | undefined>).reservation ?? null,
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

function submit(): void {
    form.service_id = selectedServiceId.value;
    form.booth_id = selectedBoothId.value;
    form.post(submitUrl('/admin/reservations'), {
        errorBag: 'reservation',
        onSuccess: () => emit('created', { date: date.value }),
    });
}
</script>

<template>
    <PanelShell
        title="新規予約"
        icon="mdi-calendar-plus-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
            <div class="nrp__slotbox">
                <span v-if="awaitingBoardSlotSelection" class="nrp__slotbox-empty">
                    {{ MESSAGES.reservation.pickSlotOnBoard }}
                </span>
                <template v-else>
                    <span v-if="dateTimeLocked" class="nrp__slotbox-label">日付</span>
                    <span class="nrp__slotbox-date">{{ fmtDay(date) }}</span>
                    <span v-if="dateTimeLocked" class="nrp__slotbox-label">開始時間</span>
                    <span v-if="form.starts_at" class="nrp__slotbox-time">
                        {{ timeLabel(form.starts_at) }}<template v-if="selectedEndLabel">〜{{ selectedEndLabel }}</template><small v-if="selectedEndLabel && form.buffer_min > 0" class="nrp__buffer-note" data-testid="nrp-buffer-note">{{ MESSAGES.visitCompletion.bufferAfter.replace('{min}', String(form.buffer_min)) }}</small>
                    </span>
                    <span v-else-if="loadingSlots" class="nrp__slotbox-empty">{{ MESSAGES.common.loading }}</span>
                    <span v-else class="nrp__slotbox-empty">--:-- 〜 --:--</span>
                </template>
                <span v-if="selectedStaffName" class="nrp__slotbox-staff">
                    <span class="nrp__slotbox-label">担当</span>{{ selectedStaffName }}
                </span>
            </div>
            <p v-if="unavailableDesiredTime !== null" class="nrp__error">
                {{ unavailableDesiredTimeMessage(unavailableDesiredTime) }}
            </p>
            <p v-else-if="dateTimeLocked && selectedServiceId === null" class="nrp__muted">
                {{ MESSAGES.reservation.pickMenuToFixTime }}
            </p>
            <p v-if="dateTimeLocked && form.errors.starts_at" class="nrp__error">
                {{ form.errors.starts_at }}
            </p>

            <!-- 全項目「上にラベル・下に値」で統一する（キャプションの出方を揃える）。 -->

            <!-- ① 顧客：上の常時表示の検索欄で選ぶ。未選択のときはハイフン。 -->
            <div class="nrp__block">
                <span class="nrp__block-label">顧客</span>

                <div v-if="selectedCustomer" class="nrp__customer">
                    <span class="nrp__customer-body">
                        <span class="nrp__customer-name">{{ selectedCustomer.name }}</span>
                        <span v-if="selectedCustomer.kana" class="nrp__customer-kana">
                            {{ selectedCustomer.kana }}
                        </span>
                    </span>
                    <button type="button" class="nrp__customer-clear" @click="clearCustomer">
                        変更
                    </button>
                </div>
                <template v-else>
                    <p class="nrp__value nrp__value--empty">---</p>
                    <button type="button" class="nrp__newcust" @click="openProvisional">
                        <v-icon icon="mdi-account-plus-outline" size="14" />
                        <span>新規のお客様（電話予約など）</span>
                        <v-icon icon="mdi-chevron-right" size="14" class="nrp__newcust-arrow" />
                    </button>
                </template>
                <p v-if="form.errors.customer_id" class="nrp__error">{{ form.errors.customer_id }}</p>
            </div>

            <!-- 仮登録：電話口で聞けた情報だけで顧客を作る。後から顧客詳細で上書きする前提。 -->
            <v-dialog v-model="provisionalOpen" max-width="360">
                <v-card>
                    <v-card-title class="text-subtitle-2 font-weight-bold">
                        新規のお客様を登録
                    </v-card-title>
                    <v-card-text>
                        <p class="nrp__hint mb-3">
                            {{ MESSAGES.reservation.provisionalCustomerHint }}
                        </p>
                        <v-text-field
                            v-model="provisional.name"
                            label="氏名（分かれば）"
                            placeholder="例：山田 太郎"
                            density="compact"
                            variant="outlined"
                            hide-details
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="provisional.kana"
                            label="カナ（分かれば）"
                            placeholder="例：ヤマダ タロウ"
                            density="compact"
                            variant="outlined"
                            hide-details
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="provisional.phone"
                            label="電話番号（分かれば）"
                            placeholder="例：09012345678"
                            density="compact"
                            variant="outlined"
                            hide-details
                        />
                        <p v-if="provisionalError" class="nrp__error mt-2">{{ provisionalError }}</p>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn
                            variant="text"
                            size="small"
                            :disabled="provisionalSaving"
                            @click="provisionalOpen = false"
                        >
                            やめる
                        </v-btn>
                        <v-btn
                            color="primary"
                            variant="flat"
                            size="small"
                            :loading="provisionalSaving"
                            @click="submitProvisional"
                        >
                            登録して選択
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- ② メニュー -->
            <div class="nrp__block">
                <span class="nrp__block-label">メニュー</span>
                <button
                    type="button"
                    class="nrp__field-btn"
                    aria-haspopup="dialog"
                    :aria-label="`メニュー：${selectedService ? selectedService.name : '未選択'}`"
                    @click="menuPickerOpen = true"
                >
                    <span class="nrp__field-value">
                        <span
                            v-if="selectedService"
                            class="nrp__field-swatch"
                            :style="{ background: selectedService.color }"
                            aria-hidden="true"
                        />
                        <span :class="{ 'nrp__field-placeholder': !selectedService }">
                            {{ selectedService ? selectedService.name : '選択してください' }}
                        </span>
                    </span>
                    <v-icon icon="mdi-chevron-right" size="16" class="nrp__field-arrow" />
                </button>
                <p v-if="form.errors.service_id" class="nrp__error">{{ form.errors.service_id }}</p>
            </div>

            <MenuPicker
                v-model="menuPickerOpen"
                :services="services"
                :popular-service-ids="popularServiceIds"
                :selected-id="selectedServiceId"
                @select="(id) => { selectedServiceId = id; }"
            />

            <!-- ③ 日付（予約の操作順：顧客→メニュー→日時→担当→ブース→インターバル→備考。Task 11-30） -->
            <div v-if="!dateTimeLocked && !awaitingBoardSlotSelection" class="nrp__block">
                <span class="nrp__block-label">日付</span>
                <DateField v-model="date" label="" density="compact" :clearable="false" />
            </div>

            <!-- ④ 開始時間 -->
            <div v-if="!dateTimeLocked && !awaitingBoardSlotSelection" class="nrp__block">
                <span class="nrp__block-label">
                    開始時間
                    <span v-if="loadingSlots" class="nrp__block-note">{{ MESSAGES.common.loading }}</span>
                </span>

                <p v-if="selectedServiceId === null" class="nrp__value nrp__value--empty">---</p>
                <p v-else-if="availabilityLoaded && slots.length === 0" class="nrp__muted">
                    {{ MESSAGES.availability.noneForCondition }}
                </p>
                <div v-else class="nrp__slots">
                    <button
                        v-for="slot in slots"
                        :key="slot.starts_at"
                        type="button"
                        class="nrp__slot"
                        :class="{ 'nrp__slot--active': form.starts_at === slot.starts_at }"
                        @click="selectSlot(slot)"
                    >
                        {{ timeLabel(slot.starts_at) }}
                    </button>
                </div>
                <p v-if="form.errors.starts_at" class="nrp__error">{{ form.errors.starts_at }}</p>
            </div>

            <!-- ⑤ 担当スタッフ・指名 -->
            <div class="nrp__block">
                <span class="nrp__block-label">担当スタッフ</span>
                <v-select
                    v-model="selectedStaffId"
                    :items="staffSelectItems"
                    item-title="title"
                    item-value="value"
                    density="compact"
                    variant="outlined"
                    hide-details="auto"
                    :error-messages="form.errors.staff_id"
                />
                <v-checkbox
                    v-if="selectedStaffId !== null"
                    v-model="form.is_staff_requested"
                    label="指名（顧客がこのスタッフを希望）"
                    density="compact"
                    hide-details
                    class="nrp__nomination"
                />
            </div>

            <!-- ⑥ ブース（自動・変更可） -->
            <div class="nrp__block">
                <span class="nrp__block-label">ブース</span>
                <!-- 空いているブースを自動で提案し、別の空きブースへ変更もできる（Task 11-28）。
                     未選択のまま予約すると、メニューで使えるブースのうち空いている1つを確定する。 -->
                <v-select
                    v-model="selectedBoothId"
                    :items="boothOptions"
                    item-title="name"
                    item-value="id"
                    density="compact"
                    variant="outlined"
                    hide-details
                    clearable
                    :placeholder="MESSAGES.bookingResources.boothAuto"
                    data-testid="nrp-booth"
                />
                <p v-if="form.errors.booth_id" class="nrp__error">{{ form.errors.booth_id }}</p>
            </div>

            <!-- ⑦ インターバル（予約の後ろに確保） -->
            <div class="nrp__block">
                <span class="nrp__block-label">インターバル</span>
                <div class="nrp__buffers" role="radiogroup" aria-label="インターバル">
                    <button
                        v-for="opt in BUFFER_OPTIONS"
                        :key="opt.value"
                        type="button"
                        class="nrp__buffer"
                        :class="{ 'nrp__buffer--active': form.buffer_min === opt.value }"
                        role="radio"
                        :aria-checked="form.buffer_min === opt.value"
                        @click="form.buffer_min = opt.value"
                    >
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <!-- ⑧ 予約備考 -->
            <div class="nrp__block">
                <span class="nrp__block-label">予約備考</span>
                <v-textarea
                    v-model="form.notes"
                    placeholder="例：初回カウンセリングあり"
                    rows="2"
                    auto-grow
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                    :error-messages="form.errors.notes"
                />
            </div>

            <!-- 予約ではない「スタッフ予定」（休憩・清掃など）は控えめな補助操作にする（Task 11-30）。 -->
            <div class="nrp__secondary">
                <v-btn variant="text" size="x-small" prepend-icon="mdi-calendar-clock-outline" data-testid="switch-to-block" @click="emit('switchToBlock')">
                    {{ MESSAGES.schedule.addStaffBlock }}
                </v-btn>
            </div>

            <p v-if="reservationConflictError" class="nrp__error">{{ reservationConflictError }}</p>

        <template #footer>
            <!-- 予約内容の確認（作成ボタンの直前に1〜2行で） -->
            <div v-if="form.starts_at && selectedService" class="nrp__summary" data-testid="nrp-summary">
                <strong>{{ timeLabel(form.starts_at) }}〜{{ selectedEndLabel }}</strong>
                <span>{{ selectedService.name }}</span>
                <span v-if="selectedStaffName">{{ selectedStaffName }}<template v-if="form.is_staff_requested">（指名）</template></span>
                <span>{{ selectedBoothName ?? MESSAGES.bookingResources.boothAuto }}</span>
                <span v-if="form.buffer_min > 0" class="nrp__summary-muted">{{ MESSAGES.visitCompletion.bufferAfter.replace('{min}', String(form.buffer_min)) }}</span>
            </div>
            <v-btn
                color="primary"
                variant="flat"
                size="small"
                block
                :disabled="form.starts_at === null || form.customer_id === null"
                :loading="form.processing"
                @click="submit"
            >
                予約を作成
            </v-btn>
        </template>
    </PanelShell>
</template>

<style scoped>
.nrp__slotbox {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 4px var(--ark-space-2);
    padding: var(--ark-space-2) var(--ark-space-3);
    background: rgba(var(--v-theme-primary), 0.06);
    border: 1px solid rgba(var(--v-theme-primary), 0.14);
    border-radius: var(--ark-radius);
}

.nrp__slotbox-date {
    font-size: 0.75rem;
    font-weight: 800;
}

.nrp__slotbox-time {
    font-size: 0.8125rem;
    font-weight: 800;
    color: rgb(var(--v-theme-primary));
    font-variant-numeric: tabular-nums;
}

.nrp__slotbox-staff {
    margin-left: auto;
    font-size: 0.6875rem;
}

.nrp__slotbox-label {
    margin-right: 3px;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.nrp__field-btn {
    display: flex;
    box-sizing: border-box;
    align-items: center;
    gap: var(--ark-space-2);
    width: 100%;
    height: 40px;
    padding: 0 var(--ark-space-3);
    text-align: left;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.3);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    cursor: pointer;
    text-align: left;
}

.nrp__field-btn:hover {
    border-color: rgb(var(--v-theme-primary));
}

.nrp__field-value {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1 1 auto;
    min-width: 0;
    font-size: 0.75rem;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.nrp__field-placeholder {
    font-weight: 400;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.nrp__field-swatch {
    flex: 0 0 auto;
    width: 9px;
    height: 9px;
    border-radius: 999px;
}

.nrp__field-arrow {
    flex: 0 0 auto;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

/* 「代わりに予定を入れる」は下線リンクではなく、入力欄と同じ幅の控えめなボタンにする。 */
.nrp__switch {
    display: flex;
    box-sizing: border-box;
    align-items: center;
    gap: 6px;
    width: 100%;
    height: 36px;
    padding: 0 var(--ark-space-3);
    border: 1px dashed rgba(var(--v-theme-primary), 0.4);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-primary), 0.04);
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    cursor: pointer;
}

.nrp__switch:hover {
    background: rgba(var(--v-theme-primary), 0.1);
}

.nrp__switch-arrow {
    margin-left: auto;
}

.nrp__nomination {
    margin-top: -4px;
}

/* ラベル＋中身をひとかたまりにするブロック（顧客・バッファ・開始時間で使う）。 */
.nrp__block {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.nrp__block-label {
    display: flex;
    align-items: baseline;
    gap: var(--ark-space-2);
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.nrp__block-note {
    font-weight: 400;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.nrp__hint {
    margin: 0;
    font-size: 0.625rem;
    line-height: 1.5;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

/* 確定済みの顧客ラベル。 */
.nrp__customer {
    display: flex;
    box-sizing: border-box;
    align-items: center;
    gap: var(--ark-space-2);
    width: 100%;
    min-height: 40px;
    padding: var(--ark-space-2) var(--ark-space-3);
    border: 1px solid rgba(var(--v-theme-primary), 0.35);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-primary), 0.06);
}

.nrp__customer-body {
    display: flex;
    flex: 1 1 auto;
    min-width: 0;
    flex-direction: column;
    gap: 1px;
}

.nrp__customer-name {
    font-size: 0.8125rem;
    font-weight: 800;
    word-break: break-word;
}

.nrp__customer-kana {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.nrp__customer-clear {
    flex: 0 0 auto;
    padding: 2px 8px;
    border: 1px solid rgba(var(--v-theme-accent), 0.5);
    border-radius: 999px;
    background: none;
    font-size: 0.625rem;
    font-weight: 700;
    color: rgb(var(--v-theme-accent));
    cursor: pointer;
}

.nrp__newcust {
    display: flex;
    box-sizing: border-box;
    align-items: center;
    gap: 6px;
    width: 100%;
    height: 34px;
    padding: 0 var(--ark-space-3);
    border: 1px dashed rgba(var(--v-theme-accent), 0.5);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-accent), 0.05);
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgb(var(--v-theme-accent));
    cursor: pointer;
}

.nrp__newcust:hover {
    background: rgba(var(--v-theme-accent), 0.12);
}

.nrp__newcust-arrow {
    margin-left: auto;
}

/* ラベルの下に出す「値」。未確定はハイフンで揃える。 */
.nrp__value {
    display: flex;
    box-sizing: border-box;
    align-items: center;
    min-height: 40px;
    margin: 0;
    padding: 0 var(--ark-space-3);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.15);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-on-surface), 0.02);
    font-size: 0.75rem;
    font-weight: 700;
}

.nrp__value--empty {
    font-weight: 400;
    color: rgba(var(--v-theme-on-surface), 0.5);
}

.nrp__customer-empty-icon {
    color: rgb(var(--v-theme-primary));
}

.nrp__cust-results {
    display: flex;
    flex-direction: column;
    max-height: 168px;
    overflow-y: auto;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
    border-radius: var(--ark-radius);
}

.nrp__cust-row {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
    padding: var(--ark-space-2) var(--ark-space-3);
    border: 0;
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
    background: none;
    text-align: left;
    cursor: pointer;
}

.nrp__cust-row:first-child {
    border-top: 0;
}

.nrp__cust-row:hover {
    background: rgba(var(--v-theme-primary), 0.08);
}

.nrp__cust-row-name {
    font-size: 0.75rem;
    font-weight: 700;
}

.nrp__cust-row-kana {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.nrp__cust-row-arrow {
    margin-left: auto;
    color: rgba(var(--v-theme-on-surface), 0.4);
}

/* 施術後にあける時間（バッファ）の選択チップ。 */
.nrp__buffer-note {
    margin-left: 6px;
    font-weight: 500;
    font-size: 0.72rem;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.nrp__secondary {
    display: flex;
    justify-content: flex-end;
    margin-top: -4px;
}

.nrp__summary {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 10px;
    margin-bottom: 8px;
    font-size: 0.78rem;
    line-height: 1.4;
    color: rgb(var(--v-theme-on-surface));
}

.nrp__summary-muted {
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.nrp__buffers {
    display: flex;
    gap: 6px;
}

.nrp__buffer {
    flex: 1 1 0;
    min-width: 0;
    height: 30px;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.74);
    cursor: pointer;
}

.nrp__buffer--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
}

/* 空き時間は自動で読み込むので、確認ボタンは置かない（§空き時間の自動取得）。 */
.nrp__slots {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.nrp__slot {
    height: 30px;
    padding: 0 10px;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    font-size: 0.6875rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: rgb(var(--v-theme-on-surface));
    cursor: pointer;
}

.nrp__slot:hover {
    border-color: rgb(var(--v-theme-primary));
}

.nrp__slot--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgb(var(--v-theme-primary));
    color: #ffffff;
}

.nrp__slotbox-empty {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.nrp__muted {
    margin: 0;
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.nrp__error {
    margin: 0;
    font-size: 0.6875rem;
    color: rgb(var(--v-theme-error));
}
</style>
