<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import PanelShell from '@/components/admin/PanelShell.vue';
import MenuPicker from '@/components/admin/MenuPicker.vue';
import { DateField, StatusChip } from '@/components/ark';
import { applyReservationPrefill, type ReservationDraft } from '@/composables/reservationDraft';
import { isPastDateTime } from '@/utils/pastDateTime';
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
    /**
     * 作成成功時の処理（下書きのクリア・戻る履歴のクリア）。emit ではなく関数で受け取る。
     * 作成後はサーバーが台帳へリダイレクトし、このパネルは onSuccess より先にアンマウントされる。
     * Vue はアンマウント後の emit を捨てるため、emit だと下書きが残り、次の予約に前回の顧客・
     * メニュー・ブースが引き継がれていた。
     */
    afterCreate?: (payload: { date: string }) => void;
}>(), {
    canGoBack: false,
    popularServiceIds: () => [],
    afterCreate: undefined,
});

const emit = defineEmits<{
    close: [];
    back: [];
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

const GENDER_PREFERENCES = [
    { value: 'male', label: '男性希望' },
    { value: 'female', label: '女性希望' },
];

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
    staff_gender_preference: props.draft.staff_gender_preference as string | null,
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
        props.draft.staff_gender_preference = form.staff_gender_preference as 'male' | 'female' | null;
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

/** 手順の番号（日時が盤面で決まっている時は「日時」の手順を出さないので、番号を詰める）。 */
type StepKey = 'customer' | 'menu' | 'time' | 'staff' | 'options';
function stepNo(key: StepKey): number {
    const order: StepKey[] = ['customer', 'menu'];
    if (!dateTimeLocked.value && !awaitingBoardSlotSelection.value) {
        order.push('time');
    }
    order.push('staff', 'options');

    return order.indexOf(key) + 1;
}
const customerDone = computed(() => form.customer_id !== null);
/** 担当を選んだか、指名なしでも日時とメニューが決まって自動割当できる状態。 */
const staffDone = computed(() => (selectedStaffId.value !== null && !selectedStaffIneligible.value) || (selectedStaffId.value === null && selectedServiceId.value !== null && form.starts_at !== null));

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

const staffSelectItems = computed(() => {
    const items = [
        { title: '指名なし（自動割当）', value: null as number | null },
        ...staffItems.value.map((s) => ({ title: s.display_name, value: s.user_id as number | null })),
    ];
    // 空き枠クリック等で、このメニューを担当できない（施術可否・資格なし）スタッフが選ばれている時も、
    // ID の数字ではなく名前で出し、担当できないことが分かるようにする。
    const current = props.staff.find((s) => s.user_id === selectedStaffId.value);
    if (current && !staffItems.value.some((s) => s.user_id === current.user_id)) {
        items.push({ title: `${current.display_name}${MESSAGES.reservation.staffNotEligibleSuffix}`, value: current.user_id });
    }

    return items;
});

/** 選択中の担当がこのメニューを担当できない時の案内。 */
const selectedStaffIneligible = computed(() => selectedService.value !== null && selectedStaffId.value !== null
    && !selectedService.value.staff_ids.includes(selectedStaffId.value));

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
    const list = allowed.length === 0 ? [...props.booths] : props.booths.filter((booth) => allowed.includes(booth.id));
    // メニュー変更前に選んだブースが対象外になっても、ID の数字ではなく名前で出す。
    const current = props.booths.find((booth) => booth.id === selectedBoothId.value);
    if (current && !list.some((booth) => booth.id === current.id)) {
        list.push({ ...current, name: `${current.name}${MESSAGES.reservation.boothNotAllowedSuffix}` });
    }

    return list;
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
const provisionalSaving = ref(false);
const provisionalError = ref<string | null>(null);
const provisional = ref({ name: '', kana: '', phone: '' });

/** お客様欄の切り替え：既存のお客様（上の検索で選ぶ）／新規のお客様（名前だけダイアログで仮登録）。 */
const customerMode = ref<'existing' | 'new'>('existing');
const provisionalOpen = ref(false);
/** 「新規のお客様」で仮登録したお客様のID。これを選んだ直後は「新規」のまま表示する。 */
const provisionalCreatedId = ref<number | null>(null);

function setCustomerMode(mode: 'existing' | 'new'): void {
    if (mode === customerMode.value && mode === 'existing') {
        return;
    }
    customerMode.value = mode;
    if (mode === 'new') {
        if (form.customer_id !== null) {
            clearCustomer();
        }
        provisionalCreatedId.value = null;
        provisional.value = { name: '', kana: '', phone: '' };
        provisionalError.value = null;
        provisionalOpen.value = true;
    }
}

// ダイアログを閉じて顧客が未登録なら「既存」に戻す。
watch(provisionalOpen, (open) => {
    if (!open && form.customer_id === null) {
        customerMode.value = 'existing';
    }
});

/** 顧客詳細ページへ移る（入力中の予約は破棄される）。 */
function openCustomerPage(customerId: number): void {
    router.visit(`/admin/customers/${customerId}`);
}

// 検索で顧客が選ばれたら「既存」に戻す。
watch(() => form.customer_id, (id) => {
    if (id !== null && id !== provisionalCreatedId.value) {
        customerMode.value = 'existing';
    }
});

/** 選んだお客様の「履歴」「今後の予約」をパネルの下に出す（顧客詳細へは「顧客」ボタンで移る）。 */
interface CustomerRow {
    id: number;
    date: string;
    starts_at: string;
    status: string;
    status_label: string;
    service_name: string;
    staff_name: string | null;
}
const customerSection = ref<'history' | 'upcoming' | null>(null);
const customerRows = ref<{ upcoming: CustomerRow[]; history: CustomerRow[]; total: number; loadedFor: number | null }>({
    upcoming: [], history: [], total: 0, loadedFor: null,
});
const customerRowsLoading = ref(false);

async function toggleCustomerSection(target: 'history' | 'upcoming'): Promise<void> {
    if (customerSection.value === target) {
        customerSection.value = null;

        return;
    }
    customerSection.value = target;
    const id = form.customer_id;
    if (id === null || customerRows.value.loadedFor === id) {
        return;
    }
    customerRowsLoading.value = true;
    try {
        const response = await fetch(`/admin/customers/${id}/board-panel?date=${date.value}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (response.ok) {
            const json = await response.json() as { upcoming: CustomerRow[]; history: { items: CustomerRow[]; total: number } };
            customerRows.value = { upcoming: json.upcoming, history: json.history.items, total: json.history.total, loadedFor: id };
        }
    } finally {
        customerRowsLoading.value = false;
    }
}

// お客様が変わったら、開いていた履歴は閉じる。
watch(() => form.customer_id, () => {
    customerSection.value = null;
    customerRows.value = { upcoming: [], history: [], total: 0, loadedFor: null };
});

async function submitProvisional(): Promise<void> {
    const body = provisional.value;

    if ([body.kana, body.phone].every((v) => v.trim() === '')) {
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
        provisionalCreatedId.value = created.user_id;
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

/** 指名と性別希望は同時に選べない。片方を選ぶともう片方は外す。 */
function toggleNomination(): void {
    form.is_staff_requested = !form.is_staff_requested;
    if (form.is_staff_requested) {
        form.staff_gender_preference = null;
    }
}

function togglePreference(value: string): void {
    form.staff_gender_preference = form.staff_gender_preference === value ? null : value;
    if (form.staff_gender_preference !== null) {
        form.is_staff_requested = false;
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

/**
 * 取れなかった理由（担当不可・資格・勤務外・他予約・ブース満室など）。サーバーが空き枠計算と同じ規則で返す。
 * 以前は「このメニュー・担当では空いていません」だけで、何を直せば取れるのか分からなかった。
 */
const unavailableReasons = ref<string[]>([]);
const reasonToast = ref(false);
let reasonsRequestId = 0;

watch(unavailableDesiredTime, async (time) => {
    unavailableReasons.value = [];
    if (time === null || desiredStartsAt.value === null || selectedServiceId.value === null) {
        return;
    }
    const requestId = ++reasonsRequestId;
    const params = new URLSearchParams({
        service_id: String(selectedServiceId.value),
        starts_at: desiredStartsAt.value,
        buffer_min: String(form.buffer_min),
    });
    if (selectedStaffId.value !== null) {
        params.set('staff_id', String(selectedStaffId.value));
    }
    if (selectedBoothId.value !== null) {
        params.set('booth_id', String(selectedBoothId.value));
    }
    try {
        const response = await fetch(`/admin/reservations/unavailable-reasons?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const json = response.ok ? ((await response.json()) as { reasons: string[] }) : { reasons: [] };
        if (requestId !== reasonsRequestId) {
            return;
        }
        unavailableReasons.value = json.reasons.length > 0 ? json.reasons : [MESSAGES.reservation.unavailableUnknown];
    } catch {
        unavailableReasons.value = [MESSAGES.reservation.unavailableUnknown];
    }
    reasonToast.value = true;
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

const pastConfirmOpen = ref(false);
const pastConfirmLabel = computed(() => {
    const value = form.starts_at;

    return value ? value.slice(0, 16).replace(/-/g, '/') : '';
});

/** 作成ボタン。過去の日時なら確認ダイアログを挟む。 */
function requestSubmit(): void {
    const value = form.starts_at;
    if (isPastDateTime(value)) {
        pastConfirmOpen.value = true;

        return;
    }
    submit();
}

function submit(): void {
    form.service_id = selectedServiceId.value;
    form.booth_id = selectedBoothId.value;
    const afterCreate = props.afterCreate;
    const createdDate = date.value;
    form.post(submitUrl('/admin/reservations'), {
        errorBag: 'reservation',
        onSuccess: () => afterCreate?.({ date: createdDate }),
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
            <!-- 日付・時間・担当を1枚のカードに3つ並べて、いつ・誰かをひと目で分かるようにする。 -->
            <div class="nrp__when" data-testid="nrp-when">
                <p v-if="awaitingBoardSlotSelection" class="nrp__when-empty">
                    {{ MESSAGES.reservation.pickSlotOnBoard }}
                </p>
                <template v-else>
                    <div class="nrp__when-cell">
                        <span class="nrp__when-label">日付</span>
                        <span class="nrp__when-value">{{ fmtDay(date) }}</span>
                    </div>
                    <div class="nrp__when-cell nrp__when-cell--time">
                        <span class="nrp__when-label">開始時間</span>
                        <span v-if="form.starts_at" class="nrp__when-value nrp__when-value--time">
                            {{ timeLabel(form.starts_at) }}<template v-if="selectedEndLabel"><span class="nrp__when-sep">〜</span>{{ selectedEndLabel }}</template>
                        </span>
                        <span v-else-if="loadingSlots" class="nrp__when-value nrp__when-value--muted">{{ MESSAGES.common.loading }}</span>
                        <span v-else class="nrp__when-value nrp__when-value--muted" data-testid="nrp-time-empty">
                            --:-- 〜 --:--
                            <v-tooltip v-if="selectedServiceId === null" activator="parent" location="bottom">{{ MESSAGES.reservation.pickMenuToFixTime }}</v-tooltip>
                        </span>
                        <small v-if="form.starts_at && selectedEndLabel && form.buffer_min > 0" class="nrp__buffer-note" data-testid="nrp-buffer-note">{{ MESSAGES.visitCompletion.bufferAfter.replace('{min}', String(form.buffer_min)) }}</small>
                    </div>
                </template>
                <div v-if="selectedStaffName" class="nrp__when-cell nrp__when-cell--staff">
                    <span class="nrp__when-label">担当</span>
                    <span class="nrp__when-value">{{ selectedStaffName }}</span>
                </div>
            </div>
            <div v-if="unavailableDesiredTime !== null" class="nrp__error" data-testid="nrp-unavailable">
                <p class="nrp__error-title">{{ unavailableDesiredTimeMessage(unavailableDesiredTime) }}</p>
                <ul v-if="unavailableReasons.length > 0" class="nrp__reasons">
                    <li v-for="reason in unavailableReasons" :key="reason">{{ reason }}</li>
                </ul>
            </div>
            <p v-if="dateTimeLocked && form.errors.starts_at" class="nrp__error">
                {{ form.errors.starts_at }}
            </p>

            <!-- 全項目「上にラベル・下に値」で統一する（キャプションの出方を揃える）。 -->

            <div class="nrp__step" :class="{ 'nrp__step--done': customerDone }">
                <span class="nrp__step-no"><v-icon v-if="customerDone" icon="mdi-check" size="13" /><template v-else>{{ stepNo('customer') }}</template></span>
                <span class="nrp__step-title">お客様</span>
            </div>

            <!-- ① お客様：既存（上の検索で選ぶ）／新規（その場で仮登録）をトグルで切り替える。 -->
            <div class="nrp__block">
                <div class="nrp__toggle" role="radiogroup" aria-label="お客様の種類">
                    <button type="button" class="nrp__toggle-btn" :class="{ 'nrp__toggle-btn--active': customerMode === 'existing' }" role="radio" :aria-checked="customerMode === 'existing'" data-testid="nrp-mode-existing" @click="setCustomerMode('existing')">既存のお客様</button>
                    <button type="button" class="nrp__toggle-btn" :class="{ 'nrp__toggle-btn--active': customerMode === 'new' }" role="radio" :aria-checked="customerMode === 'new'" data-testid="nrp-mode-new" @click="setCustomerMode('new')">新規のお客様</button>
                </div>

                <template v-if="selectedCustomer">
                    <div class="nrp__customer">
                        <span class="nrp__customer-body">
                            <span class="nrp__customer-name">{{ selectedCustomer.name }}</span>
                            <span v-if="selectedCustomer.kana" class="nrp__customer-kana">{{ selectedCustomer.kana }}</span>
                        </span>
                    </div>
                    <!-- 顧客（詳細へ）／履歴／今後の予約。予約の入力内容は残したまま、履歴は下に開く。 -->
                    <div class="nrp__tabs" role="tablist" aria-label="お客様の情報">
                        <button type="button" class="nrp__tab" data-testid="nrp-open-customer" @click="openCustomerPage(selectedCustomer.user_id)">
                            <v-icon icon="mdi-account-outline" size="16" /><span>顧客</span>
                        </button>
                        <button type="button" class="nrp__tab" :class="{ 'nrp__tab--active': customerSection === 'history' }" role="tab" :aria-selected="customerSection === 'history'" data-testid="nrp-tab-history" @click="toggleCustomerSection('history')">
                            <v-icon icon="mdi-history" size="16" /><span>履歴</span>
                        </button>
                        <button type="button" class="nrp__tab" :class="{ 'nrp__tab--active': customerSection === 'upcoming' }" role="tab" :aria-selected="customerSection === 'upcoming'" data-testid="nrp-tab-upcoming" @click="toggleCustomerSection('upcoming')">
                            <v-icon icon="mdi-calendar-clock-outline" size="16" /><span>今後の予約</span>
                        </button>
                    </div>
                </template>
                <div v-else class="nrp__customer-empty">
                    <v-icon icon="mdi-magnify" size="16" />
                    <span>{{ MESSAGES.reservation.pickCustomerFromSearch }}</span>
                </div>

                <p v-if="form.errors.customer_id" class="nrp__error">{{ form.errors.customer_id }}</p>

            </div>

            <!-- 新規のお客様：名前だけ入力して仮登録し、お客様欄に反映する（詳細は後から顧客詳細で）。 -->
            <v-dialog v-model="provisionalOpen" max-width="360">
                <v-card>
                    <v-card-title class="text-subtitle-2 font-weight-bold">新規のお客様</v-card-title>
                    <v-card-text>
                        <div class="nrp__dialog-fields">
                            <v-text-field
                                v-model="provisional.kana"
                                label="カナ"
                                placeholder="例：ヤマダ タロウ"
                                density="compact"
                                variant="outlined"
                                hide-details
                                autofocus
                                @keydown.enter.prevent="submitProvisional"
                            />
                            <v-text-field
                                v-model="provisional.phone"
                                label="電話番号"
                                placeholder="例：09012345678"
                                inputmode="tel"
                                density="compact"
                                variant="outlined"
                                hide-details
                                @keydown.enter.prevent="submitProvisional"
                            />
                        </div>
                        <p v-if="provisionalError" class="nrp__error mt-2">{{ provisionalError }}</p>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" size="small" :disabled="provisionalSaving" @click="provisionalOpen = false">やめる</v-btn>
                        <v-btn color="primary" variant="flat" size="small" :loading="provisionalSaving" @click="submitProvisional">登録して選択</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- ② メニュー -->
            <div class="nrp__step" :class="{ 'nrp__step--done': selectedServiceId !== null }">
                <span class="nrp__step-no"><v-icon v-if="selectedServiceId !== null" icon="mdi-check" size="13" /><template v-else>{{ stepNo('menu') }}</template></span>
                <span class="nrp__step-title">メニュー</span>
            </div>
            <div class="nrp__block">
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
                        <span class="nrp__field-text">
                            <span :class="{ 'nrp__field-placeholder': !selectedService }">
                                {{ selectedService ? selectedService.name : '選択してください' }}
                            </span>
                            <span v-if="selectedService" class="nrp__field-meta">
                                {{ selectedService.duration_min }}分 ・ {{ selectedService.price.toLocaleString() }}円
                            </span>
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

            <div v-if="!dateTimeLocked && !awaitingBoardSlotSelection" class="nrp__step" :class="{ 'nrp__step--done': form.starts_at !== null }">
                <span class="nrp__step-no"><v-icon v-if="form.starts_at !== null" icon="mdi-check" size="13" /><template v-else>{{ stepNo('time') }}</template></span>
                <span class="nrp__step-title">日時</span>
            </div>

            <!-- ③ 日付（予約の操作順：顧客→メニュー→日時→担当→ブース→インターバル→備考。Task 11-30） -->
            <div v-if="!dateTimeLocked && !awaitingBoardSlotSelection" class="nrp__block">
                <span class="nrp__block-label">日付</span>
                <DateField block v-model="date" label="" density="compact" :clearable="false" />
            </div>

            <!-- ④ 開始時間 -->
            <div v-if="!dateTimeLocked && !awaitingBoardSlotSelection" class="nrp__block">
                <span class="nrp__block-label">
                    開始時間
                    <span v-if="loadingSlots" class="nrp__block-note">{{ MESSAGES.common.loading }}</span>
                </span>

                <p v-if="selectedServiceId === null" class="nrp__value nrp__value--empty">{{ MESSAGES.common.emptyValue }}</p>
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

            <div class="nrp__step" :class="{ 'nrp__step--done': staffDone }">
                <span class="nrp__step-no"><v-icon v-if="staffDone" icon="mdi-check" size="13" /><template v-else>{{ stepNo('staff') }}</template></span>
                <span class="nrp__step-title">担当・ブース</span>
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
                    :hint="selectedStaffIneligible ? MESSAGES.reservation.staffNotEligibleHint : undefined"
                    persistent-hint
                />
                <!-- 指名／男性希望／女性希望。性別希望は担当を決めていなくても付けられる（押し直すと外れる）。 -->
                <div class="nrp__prefs" role="group" aria-label="担当の希望">
                    <button
                        type="button"
                        class="nrp__pref nrp__pref--nomination"
                        :class="{ 'nrp__pref--active': form.is_staff_requested }"
                        :disabled="selectedStaffId === null"
                        :aria-pressed="form.is_staff_requested"
                        data-testid="nrp-pref-nomination"
                        @click="toggleNomination"
                    >指名</button>
                    <button
                        v-for="g in GENDER_PREFERENCES"
                        :key="g.value"
                        type="button"
                        class="nrp__pref"
                        :class="[`nrp__pref--${g.value}`, { 'nrp__pref--active': form.staff_gender_preference === g.value }]"
                        :aria-pressed="form.staff_gender_preference === g.value"
                        :data-testid="`nrp-pref-${g.value}`"
                        @click="togglePreference(g.value)"
                    >{{ g.label }}</button>
                </div>
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

            <div class="nrp__step" >
                <span class="nrp__step-no">{{ stepNo('options') }}</span>
                <span class="nrp__step-title">オプション</span>
                <span class="nrp__step-opt">任意</span>
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

            <!-- 予約ではない「スタッフ予定」（休憩・清掃など）は控えめな補助操作にする（予定パネルの「予約に切り替え」も同じ形）。 -->
            <div class="nrp__secondary">
                <v-btn variant="text" size="x-small" prepend-icon="mdi-calendar-clock-outline" data-testid="switch-to-block" @click="emit('switchToBlock')">
                    {{ MESSAGES.schedule.addStaffBlock }}
                </v-btn>
            </div>

            <!-- 履歴・今後の予約（選んだお客様のもの）は、スタッフ予定の追加より下の別セクションに出す。 -->
            <div v-if="customerSection !== null && selectedCustomer" class="nrp__history" data-testid="nrp-rows">
                <h3 class="nrp__history-title">
                    <v-icon :icon="customerSection === 'history' ? 'mdi-history' : 'mdi-calendar-clock-outline'" size="16" />
                    {{ customerSection === 'history' ? '来店履歴' : '今後の予約' }}
                    <button type="button" class="nrp__history-close" aria-label="閉じる" @click="customerSection = null"><v-icon icon="mdi-close" size="14" /></button>
                </h3>
                <p v-if="customerRowsLoading" class="nrp__muted">{{ MESSAGES.common.loading }}</p>
                <template v-else>
                    <p v-if="(customerSection === 'history' ? customerRows.history : customerRows.upcoming).length === 0" class="nrp__muted">
                        {{ customerSection === 'history' ? MESSAGES.reservation.noVisitHistory : MESSAGES.reservation.noUpcoming }}
                    </p>
                    <div v-for="row in (customerSection === 'history' ? customerRows.history : customerRows.upcoming)" :key="row.id" class="nrp__row">
                        <span class="nrp__row-top">
                            <span class="nrp__row-when">{{ row.date }} {{ row.starts_at.slice(11, 16) }}</span>
                            <StatusChip :status="row.status" :label="row.status_label" size="x-small" />
                        </span>
                        <span class="nrp__row-service">{{ row.service_name }}</span>
                        <span class="nrp__row-staff">{{ row.staff_name ?? '担当なし' }}</span>
                    </div>
                    <p v-if="customerSection === 'history' && customerRows.total > customerRows.history.length" class="nrp__muted">全 {{ customerRows.total }} 件のうち新しい順に表示</p>
                </template>
            </div>

            <p v-if="reservationConflictError" class="nrp__error">{{ reservationConflictError }}</p>

        <template #footer>
            <!-- 予約内容の確認（作成ボタンの直前に1〜2行で） -->
            <div v-if="form.starts_at && selectedService" class="nrp__summary" data-testid="nrp-summary">
                <strong>{{ timeLabel(form.starts_at) }}〜{{ selectedEndLabel }}</strong>
                <span>{{ selectedService.name }}</span>
                <span v-if="selectedStaffName">{{ selectedStaffName }}<template v-if="form.is_staff_requested">（指名）</template></span>
                <span v-if="form.staff_gender_preference">{{ form.staff_gender_preference === 'male' ? '男性希望' : '女性希望' }}</span>
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
                @click="requestSubmit"
            >
                予約を作成
            </v-btn>
        </template>
        <!-- 取れなかった理由のトースト。画面下に出して、パネルを見ていなくても気づけるようにする。 -->
        <v-snackbar v-model="reasonToast" color="warning" location="bottom" timeout="8000" multi-line>
            <strong>{{ unavailableDesiredTime !== null ? unavailableDesiredTimeMessage(unavailableDesiredTime) : '' }}</strong>
            <ul class="nrp__toast-reasons">
                <li v-for="reason in unavailableReasons" :key="reason">{{ reason }}</li>
            </ul>
            <template #actions>
                <v-btn variant="text" @click="reasonToast = false">閉じる</v-btn>
            </template>
        </v-snackbar>
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

<style scoped src="@/components/admin/panels/NewReservationPanel.css"></style>
