<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, nextTick, ref, watch } from "vue";
import { StatusChip } from "@/components/ark";
import PanelShell from "@/components/admin/PanelShell.vue";
import { firstErrorMessage } from "@/composables/inertiaErrors";
import { MESSAGES } from "@/constants/messages";

interface HistoryRow {
    id: number;
    date: string;
    starts_at: string;
    service_id: number;
    service_name: string;
    staff_id: number | null;
    staff_name: string | null;
    status: string;
    status_label: string;
}

interface PanelData {
    can: { manage: boolean; view_customer: boolean };
    reservation: {
        id: number;
        customer_id: number;
        date: string;
        starts_at: string;
        ends_at: string;
        service_id: number;
        service_name: string;
        staff_id: number | null;
        staff_name: string | null;
        is_staff_requested: boolean;
        booth_name: string | null;
        status: string;
        status_label: string;
        source_label: string;
        payment_method_label: string;
        amount: number;
        notes: string | null;
        cancel_reason: string | null;
        version: number;
        edit_url: string;
        payment: {
            id: number;
            amount: number;
            status_label: string;
            url: string;
        } | null;
        can_complete: boolean;
        can_cancel: boolean;
        can_no_show: boolean;
    } | null;
    today_reservation_id: number | null;
    customer: {
        user_id: number;
        member_no: string;
        name: string;
        kana: string | null;
        gender: string | null;
        phone: string | null;
        note: string | null;
        visit_count: number;
        first_visit_at: string | null;
        last_visit_at: string | null;
        detail_url: string;
    } | null;
    tickets: {
        product_name: string;
        available: number;
        held: number;
        total: number;
        expires_at: string;
    }[];
    membership: {
        plan_name: string;
        status_label: string;
        available: number;
        usage_count_per_period: number;
        current_period_end: string | null;
    } | null;
    upcoming: HistoryRow[];
    history: { items: HistoryRow[]; total: number; has_more: boolean };
}

const props = withDefaults(
    defineProps<{
        reservationId: number | null;
        customerId: number | null;
        referenceDate: string;
        canGoBack?: boolean;
    }>(),
    {
        canGoBack: false,
    },
);

const emit = defineEmits<{
    close: [];
    create: [];
    back: [];
    navigate: [payload: { date: string; reservationId: number }];
    highlight: [payload: { reservationId: number }];
    rebook: [
        payload: {
            customerId: number;
            serviceId: number;
            staffId: number | null;
        },
    ];
    viewCustomer: [payload: { customerId: number }];
}>();

const moreMenuOpen = ref(false);

const data = ref<PanelData | null>(null);
const loading = ref(false);
const error = ref(false);
let requestedKey: string | null = null;

const memoExpanded = ref(false);
const memoEl = ref<HTMLElement | null>(null);
const memoOverflows = ref(false);
const memoEditing = ref(false);
const memoDraft = ref("");
const memoSaving = ref(false);

const confirmMode = ref<null | "cancel" | "no_show">(null);
const cancelReason = ref("");
const actionBusy = ref(false);
/** キャンセル・無断キャンセル・来店完了が失敗した時のメッセージ。 */
const actionError = ref<string | null>(null);

// 「顧客」「履歴」「今後の予約」の切り替え（§タブ）。既定は「今回の予約」（＝クリックした予約の情報）。
type Section = "default" | "history" | "upcoming";
const section = ref<Section>("default");

function selectSection(target: Section): void {
    section.value = section.value === target ? "default" : target;
}

function viewCustomerDetail(): void {
    const customerId = data.value?.customer?.user_id;

    if (customerId === undefined) {
        return;
    }

    emit("viewCustomer", { customerId });
}

const activeKey = computed(() => {
    if (props.reservationId !== null) {
        return `r:${props.reservationId}`;
    }
    if (props.customerId !== null) {
        return `c:${props.customerId}`;
    }

    return null;
});

async function load(): Promise<void> {
    const key = activeKey.value;

    if (key === null) {
        data.value = null;

        return;
    }

    requestedKey = key;
    actionError.value = null;
    loading.value = true;
    error.value = false;
    memoExpanded.value = false;
    memoEditing.value = false;

    try {
        const url =
            props.reservationId !== null
                ? `/admin/reservations/${props.reservationId}/panel`
                : `/admin/customers/${props.customerId}/board-panel?date=${props.referenceDate}`;
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const payload = (await response.json()) as PanelData;

        if (requestedKey === key) {
            data.value = payload;

            if (payload.today_reservation_id !== null) {
                emit("highlight", {
                    reservationId: payload.today_reservation_id,
                });
            }

            await nextTick();
            memoOverflows.value =
                !!memoEl.value &&
                memoEl.value.scrollHeight - memoEl.value.clientHeight > 2;
        }
    } catch {
        if (requestedKey === key) {
            error.value = true;
            data.value = null;
        }
    } finally {
        if (requestedKey === key) {
            loading.value = false;
        }
    }
}

watch(
    () => activeKey.value,
    () => {
        confirmMode.value = null;
        void load();
    },
    { immediate: true },
);

// 「顧客」タブで顧客詳細へ移った後、「戻る」で元の予約に戻ってきた時は
// section を初期化しない（見ていたタブのまま復元される）。新しい予約カードを
// クリックした時（reservationId が別の値に変わった時）だけ「今回の予約」に戻す。
let lastReservationId: number | null = null;
watch(
    () => props.reservationId,
    (id) => {
        if (id !== null && id !== lastReservationId) {
            section.value = "default";
        }
        if (id !== null) {
            lastReservationId = id;
        }
    },
    { immediate: true },
);

function refetch(): void {
    void load();
}

const genderLabel = computed<string | null>(() => {
    const g = data.value?.customer?.gender;

    return g === "male" ? "男" : g === "female" ? "女" : null;
});

const timeRange = computed(() => {
    const r = data.value?.reservation;

    if (!r) {
        return "";
    }

    return `${r.starts_at.slice(11, 16)}〜${r.ends_at.slice(11, 16)}`;
});

function fmtDay(iso: string): string {
    const [y, m, d] = iso.split("-").map(Number);
    const weekday = new Intl.DateTimeFormat("ja-JP", {
        weekday: "short",
    }).format(new Date(y, m - 1, d));

    return `${m}/${d}（${weekday}）`;
}

function money(value: number): string {
    return new Intl.NumberFormat("ja-JP", {
        style: "currency",
        currency: "JPY",
        maximumFractionDigits: 0,
    }).format(value);
}

function goRow(row: HistoryRow): void {
    emit("navigate", { date: row.date, reservationId: row.id });
}

function rebookRow(row: HistoryRow): void {
    const customerId =
        data.value?.customer?.user_id ?? data.value?.reservation?.customer_id;

    if (customerId === undefined) {
        return;
    }

    emit("rebook", {
        customerId,
        serviceId: row.service_id,
        staffId: row.staff_id,
    });
}

function rebookCurrent(): void {
    const reservation = data.value?.reservation;

    if (reservation === null || reservation === undefined) {
        return;
    }

    emit("rebook", {
        customerId: reservation.customer_id,
        serviceId: reservation.service_id,
        staffId: reservation.staff_id,
    });
}

function startMemoEdit(): void {
    memoDraft.value = data.value?.customer?.note ?? "";
    memoEditing.value = true;
}

function cancelMemoEdit(): void {
    memoEditing.value = false;
    memoDraft.value = "";
}

function saveMemo(): void {
    const customerId = data.value?.customer?.user_id;

    if (customerId === undefined) {
        return;
    }

    memoSaving.value = true;
    router.patch(
        `/admin/customers/${customerId}/note`,
        { note: memoDraft.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                memoEditing.value = false;
                refetch();
            },
            onFinish: () => {
                memoSaving.value = false;
            },
        },
    );
}

function runAction(
    path: string,
    body: Record<string, string | undefined> = {},
): void {
    actionBusy.value = true;
    actionError.value = null;
    router.patch(path, body, {
        preserveScroll: true,
        preserveState: true,
        errorBag: "reservation",
        onError: (errors) => {
            actionError.value =
                firstErrorMessage(errors) ??
                MESSAGES.common.actionFailedReload;
        },
        onFinish: () => {
            actionBusy.value = false;
            confirmMode.value = null;
            cancelReason.value = "";
        },
        onSuccess: () => refetch(),
    });
}

function complete(): void {
    const id = data.value?.reservation?.id;
    if (id) {
        runAction(`/admin/reservations/${id}/complete`);
    }
}

const panelTitle = computed(() =>
    data.value?.reservation ? "予約詳細" : "顧客情報",
);
const panelIcon = computed(() =>
    data.value?.reservation
        ? "mdi-calendar-account-outline"
        : "mdi-account-outline",
);

function submitConfirm(): void {
    const id = data.value?.reservation?.id;
    if (!id) {
        return;
    }
    if (confirmMode.value === "cancel") {
        runAction(`/admin/reservations/${id}/cancel`, {
            reason: cancelReason.value || undefined,
        });
    } else if (confirmMode.value === "no_show") {
        runAction(`/admin/reservations/${id}/no-show`);
    }
}
</script>

<template>
    <PanelShell
        :title="panelTitle"
        :icon="panelIcon"
        :body-padding="false"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <v-alert
            v-if="actionError"
            type="error"
            variant="tonal"
            density="compact"
            closable
            class="ma-2"
            @click:close="actionError = null"
        >
            {{ actionError }}
        </v-alert>

        <div v-if="loading" class="rdp__state">
            <v-progress-circular indeterminate size="28" color="primary" />
        </div>

        <div v-else-if="error" class="rdp__state">
            <v-icon icon="mdi-alert-circle-outline" color="error" size="26" />
            <p class="text-body-2 mt-2">{{ MESSAGES.common.loadFailed }}</p>
            <v-btn variant="tonal" size="small" class="mt-2" @click="refetch"
                >再読み込み</v-btn
            >
        </div>

        <div v-else-if="data" class="rdp__scroll">
            <!-- 顧客基本情報：名前を最優先で大きく、会員番号は控えめに下へ（§8） -->
            <section v-if="data.customer" class="rdp__sec rdp__sec--cust">
                <span v-if="data.customer.kana" class="rdp__kana">{{
                    data.customer.kana
                }}</span>
                <div class="rdp__nameline">
                    <span class="rdp__name">{{ data.customer.name }}</span>
                    <span
                        v-if="genderLabel"
                        class="rdp__gender"
                        :class="`rdp__gender--${data.customer.gender}`"
                    >
                        {{ genderLabel }}
                    </span>
                </div>
                <div class="rdp__topline">
                    <span class="rdp__memberno">{{
                        data.customer.member_no
                    }}</span>
                </div>
                <p class="rdp__tel">TEL {{ data.customer.phone ?? "---" }}</p>

                <!-- 表示切替タブ：顧客詳細／履歴／今後の予約（§タブ）。既定は「今回の予約」。 -->
                <div class="rdp__tabs" role="tablist" aria-label="表示の切り替え">
                    <button
                        v-if="data.reservation"
                        type="button"
                        class="rdp__tab"
                        role="tab"
                        @click="viewCustomerDetail"
                    >
                        <v-icon icon="mdi-account-outline" size="16" />
                        <span>顧客</span>
                    </button>
                    <button
                        type="button"
                        class="rdp__tab"
                        role="tab"
                        :aria-selected="section === 'history'"
                        :class="{ 'rdp__tab--active': section === 'history' }"
                        @click="selectSection('history')"
                    >
                        <v-icon icon="mdi-history" size="16" />
                        <span>履歴</span>
                        <v-icon
                            v-if="section === 'history'"
                            icon="mdi-close"
                            size="12"
                            class="rdp__tab-close"
                            aria-label="履歴を閉じる"
                            @click.stop="section = 'default'"
                        />
                    </button>
                    <button
                        type="button"
                        class="rdp__tab"
                        role="tab"
                        :aria-selected="section === 'upcoming'"
                        :class="{ 'rdp__tab--active': section === 'upcoming' }"
                        @click="selectSection('upcoming')"
                    >
                        <v-icon icon="mdi-calendar-clock-outline" size="16" />
                        <span>今後の予約</span>
                        <v-icon
                            v-if="section === 'upcoming'"
                            icon="mdi-close"
                            size="12"
                            class="rdp__tab-close"
                            aria-label="今後の予約を閉じる"
                            @click.stop="section = 'default'"
                        />
                    </button>
                </div>
            </section>

            <section v-else class="rdp__sec">
                <p class="rdp__muted">{{ MESSAGES.customer.viewForbidden }}</p>
            </section>

            <!-- 利用状況：数字を太字にして一瞬で分かるように（§10） -->
            <section v-if="data.customer" class="rdp__sec">
                <h3 class="rdp__h">利用状況</h3>
                <div class="rdp__stats">
                    <div class="rdp__stat">
                        <span class="rdp__stat-label">来店</span>
                        <span class="rdp__stat-value"
                            >{{ data.customer.visit_count
                            }}<small>回</small></span
                        >
                    </div>
                    <div
                        v-for="t in data.tickets"
                        :key="t.product_name + t.expires_at"
                        class="rdp__stat"
                    >
                        <span class="rdp__stat-label">{{
                            t.product_name
                        }}</span>
                        <span class="rdp__stat-value"
                            >残り {{ t.available }}<small>回</small></span
                        >
                    </div>
                    <div v-if="data.membership" class="rdp__stat">
                        <span class="rdp__stat-label">{{
                            data.membership.plan_name
                        }}</span>
                        <span class="rdp__stat-value">
                            月{{ data.membership.usage_count_per_period
                            }}<small>回</small> ／ 残り
                            {{ data.membership.available }}<small>回</small>
                        </span>
                    </div>
                </div>
            </section>

            <!-- 顧客メモ：独立セクション（§9） -->
            <section v-if="data.customer" class="rdp__sec">
                <div class="rdp__memo-head">
                    <h3 class="rdp__h rdp__h--flush">顧客メモ</h3>
                    <button
                        v-if="!memoEditing"
                        type="button"
                        class="rdp__morebtn"
                        @click="startMemoEdit"
                    >
                        {{ data.customer.note ? "編集" : "＋ メモを追加" }}
                    </button>
                </div>

                <div class="rdp__memo">
                    <template v-if="memoEditing">
                        <v-textarea
                            v-model="memoDraft"
                            placeholder="例：着替え持参／施術時の注意点など、スタッフ間で共有したいことを書いてください"
                            rows="3"
                            auto-grow
                            variant="outlined"
                            density="compact"
                            hide-details
                        />
                        <div class="rdp__memo-editactions">
                            <v-btn
                                variant="text"
                                size="small"
                                :disabled="memoSaving"
                                @click="cancelMemoEdit"
                            >
                                やめる
                            </v-btn>
                            <v-btn
                                color="primary"
                                variant="flat"
                                size="small"
                                :loading="memoSaving"
                                @click="saveMemo"
                            >
                                メモを保存
                            </v-btn>
                        </div>
                    </template>

                    <template v-else-if="data.customer.note">
                        <p
                            ref="memoEl"
                            class="rdp__memo-text"
                            :class="{ 'is-clamped': !memoExpanded }"
                        >
                            {{ data.customer.note }}
                        </p>
                        <button
                            v-if="memoOverflows"
                            type="button"
                            class="rdp__morebtn"
                            @click="memoExpanded = !memoExpanded"
                        >
                            {{ memoExpanded ? "閉じる" : "もっと見る" }}
                        </button>
                    </template>

                    <p v-else class="rdp__memo-empty">---</p>
                </div>
            </section>

            <!-- 今回の予約：顧客情報と背景を分けて一目で分かるようにする（§11・§12）。既定表示。 -->
            <section
                v-if="data.reservation && section === 'default'"
                class="rdp__sec rdp__sec--resv"
            >
                <h3 class="rdp__h">今回の予約</h3>

                <div class="rdp__resv-when">
                    <span class="rdp__resv-time">{{ timeRange }}</span>
                    <span class="rdp__resv-date">{{
                        fmtDay(data.reservation.date)
                    }}</span>
                    <StatusChip
                        :status="data.reservation.status"
                        :label="data.reservation.status_label"
                        size="x-small"
                    />
                </div>

                <dl class="rdp__facts">
                    <div class="rdp__facts-wide">
                        <dt>メニュー</dt>
                        <dd>{{ data.reservation.service_name }}</dd>
                    </div>
                    <div>
                        <dt>担当</dt>
                        <dd>
                            {{ data.reservation.staff_name ?? "担当なし" }}
                            <span
                                v-if="data.reservation.is_staff_requested"
                                class="rdp__nomination"
                            >
                                <v-icon icon="mdi-star" size="10" />指名
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>ブース</dt>
                        <dd>{{ data.reservation.booth_name ?? "---" }}</dd>
                    </div>
                </dl>

                <!-- 経路・支払い・金額は項目が少ないので、折りたたまず常に表示する。 -->
                <dl class="rdp__facts rdp__facts--detail">
                    <div>
                        <dt>経路</dt>
                        <dd>{{ data.reservation.source_label }}</dd>
                    </div>
                    <div>
                        <dt>支払い</dt>
                        <dd>{{ data.reservation.payment_method_label }}</dd>
                    </div>
                    <div>
                        <dt>金額</dt>
                        <dd>{{ money(data.reservation.amount) }}</dd>
                    </div>
                    <div v-if="data.reservation.notes" class="rdp__facts-wide">
                        <dt>予約備考</dt>
                        <dd>{{ data.reservation.notes }}</dd>
                    </div>
                    <div
                        v-if="data.reservation.cancel_reason"
                        class="rdp__facts-wide"
                    >
                        <dt>キャンセル理由</dt>
                        <dd>{{ data.reservation.cancel_reason }}</dd>
                    </div>
                </dl>
            </section>

            <!-- 今後の予約：「今後の予約」タブを押した時だけ表示（§タブ）。 -->
            <section v-if="section === 'upcoming'" class="rdp__sec">
                <h3 class="rdp__h">今後の予約</h3>
                <p v-if="!data.upcoming.length" class="rdp__muted">
                    {{ MESSAGES.reservation.noUpcoming }}
                </p>
                <div
                    v-for="row in data.upcoming"
                    :key="row.id"
                    class="rdp__row"
                    :class="{
                        'rdp__row--current':
                            data.reservation && row.id === data.reservation.id,
                    }"
                >
                    <button
                        type="button"
                        class="rdp__row-main"
                        @click="goRow(row)"
                    >
                        <span class="rdp__row-top">
                            <span class="rdp__row-when"
                                >{{ fmtDay(row.date) }}
                                {{ row.starts_at.slice(11, 16) }}</span
                            >
                            <StatusChip
                                :status="row.status"
                                :label="row.status_label"
                                size="x-small"
                            />
                        </span>
                        <span class="rdp__row-service">{{
                            row.service_name
                        }}</span>
                        <span class="rdp__row-staff">{{
                            row.staff_name ?? "担当なし"
                        }}</span>
                    </button>
                    <v-icon
                        icon="mdi-chevron-right"
                        size="16"
                        class="rdp__row-arrow"
                    />
                </div>
            </section>

            <!-- 来店履歴：「履歴」タブを押した時だけ表示（§タブ）。 -->
            <section v-if="section === 'history'" class="rdp__sec">
                <h3 class="rdp__h">来店履歴</h3>
                <p v-if="!data.history.items.length" class="rdp__muted">
                    {{ MESSAGES.reservation.noVisitHistory }}
                </p>
                <div
                    v-for="row in data.history.items"
                    :key="row.id"
                    class="rdp__row"
                    :class="{
                        'rdp__row--current':
                            data.reservation && row.id === data.reservation.id,
                    }"
                >
                    <button
                        type="button"
                        class="rdp__row-main"
                        @click="goRow(row)"
                    >
                        <span class="rdp__row-top">
                            <span class="rdp__row-when"
                                >{{ row.date }}
                                {{ row.starts_at.slice(11, 16) }}</span
                            >
                            <StatusChip
                                :status="row.status"
                                :label="row.status_label"
                                size="x-small"
                            />
                        </span>
                        <span class="rdp__row-service">{{
                            row.service_name
                        }}</span>
                        <span class="rdp__row-staff">{{
                            row.staff_name ?? "担当なし"
                        }}</span>
                    </button>
                    <v-tooltip text="この内容で再予約" location="left">
                        <template #activator="{ props: tip }">
                            <button
                                v-bind="tip"
                                type="button"
                                class="rdp__rebookbtn"
                                aria-label="この内容で再予約"
                                @click="rebookRow(row)"
                            >
                                <v-icon
                                    icon="mdi-calendar-refresh-outline"
                                    size="14"
                                />
                                <span>再予約</span>
                            </button>
                        </template>
                    </v-tooltip>
                </div>
                <a
                    v-if="data.history.has_more && data.customer"
                    class="rdp__more"
                    :href="data.customer.detail_url"
                >
                    全 {{ data.history.total }} 件を顧客詳細で見る
                </a>
            </section>
        </div>

        <!-- フッターは最大2ボタンまで。頻度が低い／危険な操作は「…」へまとめる（§15・§16） -->
        <template v-if="data" #footer>
            <div class="rdp__footer">
                <template v-if="data.reservation">
                    <v-btn
                        v-if="data.reservation.can_complete"
                        color="primary"
                        variant="flat"
                        size="small"
                        :loading="actionBusy"
                        @click="complete"
                    >
                        来店完了
                    </v-btn>
                    <v-btn
                        :href="data.reservation.edit_url"
                        color="accent"
                        variant="outlined"
                        size="small"
                    >
                        予約編集
                    </v-btn>
                </template>
                <template v-else>
                    <v-btn
                        v-if="canGoBack"
                        variant="text"
                        size="small"
                        prepend-icon="mdi-arrow-left"
                        @click="emit('back')"
                    >
                        戻る
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        size="small"
                        @click="emit('create')"
                    >
                        新規予約
                    </v-btn>
                </template>

                <v-menu v-model="moreMenuOpen" location="top end">
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            icon="mdi-dots-horizontal"
                            variant="text"
                            size="small"
                            aria-label="その他の操作"
                        />
                    </template>
                    <v-list density="compact">
                        <v-list-item
                            v-if="data.can.manage && data.reservation"
                            prepend-icon="mdi-calendar-refresh-outline"
                            title="この内容で新規予約"
                            @click="rebookCurrent"
                        />
                        <v-list-item
                            prepend-icon="mdi-calendar-plus-outline"
                            title="新規予約"
                            @click="emit('create')"
                        />
                        <v-list-item
                            v-if="data.reservation?.payment"
                            :href="data.reservation.payment.url"
                            prepend-icon="mdi-credit-card-outline"
                            title="決済確認"
                        />
                        <v-list-item
                            v-if="data.reservation?.can_no_show"
                            prepend-icon="mdi-account-off-outline"
                            title="無断キャンセル"
                            class="rdp__menu-danger"
                            @click="confirmMode = 'no_show'"
                        />
                        <v-list-item
                            v-if="data.reservation?.can_cancel"
                            prepend-icon="mdi-close-circle-outline"
                            title="キャンセル"
                            class="rdp__menu-danger"
                            @click="confirmMode = 'cancel'"
                        />
                    </v-list>
                </v-menu>
            </div>
        </template>

        <!-- キャンセル / 無断キャンセルの確認 -->
        <v-dialog
            :model-value="confirmMode !== null"
            max-width="380"
            @update:model-value="
                (v) => {
                    if (!v) confirmMode = null;
                }
            "
        >
            <v-card v-if="data && data.reservation">
                <v-card-title class="text-subtitle-1 font-weight-bold">
                    {{
                        confirmMode === "cancel"
                            ? "予約をキャンセルしますか？"
                            : "無断キャンセルにしますか？"
                    }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-2">
                        {{ data.customer?.name ?? "" }} ／
                        {{ fmtDay(data.reservation.date) }} {{ timeRange }}
                        <br />{{ data.reservation.service_name }}
                    </div>
                    <v-textarea
                        v-if="confirmMode === 'cancel'"
                        v-model="cancelReason"
                        label="キャンセル理由（任意）"
                        rows="2"
                        auto-grow
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="actionBusy"
                        @click="confirmMode = null"
                        >やめる</v-btn
                    >
                    <v-btn
                        :color="confirmMode === 'cancel' ? 'error' : 'primary'"
                        variant="flat"
                        :loading="actionBusy"
                        @click="submitConfirm"
                    >
                        {{
                            confirmMode === "cancel"
                                ? "キャンセルする"
                                : "無断キャンセルにする"
                        }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </PanelShell>
</template>

<style scoped>
.rdp__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--ark-space-7) var(--ark-space-4);
    text-align: center;
}

/* スクロールは親の .panel-shell__body 一箇所だけで行う（ここで二重に overflow を持つと
   「上までスクロールが戻らない」ような挙動になるため、ここでは持たせない）。 */
.rdp__scroll {
    flex: 1 1 auto;
    min-height: 0;
}

.rdp__sec {
    padding: var(--ark-space-3);
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.rdp__sec:last-child {
    border-bottom: 0;
}

.rdp__sec--cust {
    background: rgba(var(--v-theme-primary), 0.03);
}

.rdp__h {
    margin: 0 0 var(--ark-space-2);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: rgb(var(--v-theme-primary));
}

.rdp__kana {
    display: block;
    margin-top: var(--ark-space-2);
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.rdp__nameline {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--ark-space-2);
    margin-top: 1px;
}

.rdp__name {
    font-size: 1.0625rem;
    font-weight: 800;
}

.rdp__gender {
    padding: 0 6px;
    border-radius: var(--ark-radius-sm);
    font-size: 0.625rem;
    font-weight: 800;
    line-height: 1.7;
}

.rdp__gender--male {
    color: rgb(var(--v-theme-info));
    background: rgba(var(--v-theme-info), 0.12);
}

.rdp__gender--female {
    color: rgb(var(--v-theme-error));
    background: rgba(var(--v-theme-error), 0.1);
}

.rdp__topline {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
    margin-top: 2px;
}

.rdp__memberno {
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-variant-numeric: tabular-nums;
}

.rdp__tel {
    margin: 4px 0 0;
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.76);
}

.rdp__stats {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: var(--ark-space-2);
}

.rdp__stat {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--ark-space-2);
}

.rdp__stat-label {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rdp__stat-value {
    flex: 0 0 auto;
    font-size: 0.8125rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.rdp__stat-value small {
    font-size: 0.625rem;
    font-weight: 600;
}

.rdp__sec--resv {
    background: rgba(var(--v-theme-accent), 0.05);
}

.rdp__resv-when {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
    margin-bottom: var(--ark-space-2);
}

.rdp__resv-time {
    font-size: 0.9375rem;
    font-weight: 800;
    color: rgb(var(--v-theme-primary));
    font-variant-numeric: tabular-nums;
}

.rdp__resv-date {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.rdp__h--flush {
    margin-bottom: 0;
}

.rdp__facts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px var(--ark-space-3);
    margin: var(--ark-space-2) 0 0;
}

.rdp__facts > div {
    min-width: 0;
}

.rdp__facts-wide {
    grid-column: 1 / -1;
}

.rdp__facts--detail {
    margin-top: var(--ark-space-3);
}

.rdp__facts dt {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.rdp__facts dd {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.4;
    word-break: break-word;
}

.rdp__memo {
    margin-top: var(--ark-space-2);
    padding: var(--ark-space-2) var(--ark-space-3);
    background: rgba(var(--v-theme-warning), 0.1);
    border-radius: var(--ark-radius);
}

.rdp__memo-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-2);
}

.rdp__memo-text {
    margin: 2px 0 0;
    font-size: 0.75rem;
    line-height: 1.5;
    white-space: pre-wrap;
}

.rdp__memo-empty {
    margin: 2px 0 0;
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.rdp__memo-editactions {
    display: flex;
    justify-content: flex-end;
    gap: var(--ark-space-2);
    margin-top: var(--ark-space-2);
}

.rdp__memo :deep(.v-textarea) {
    margin-top: var(--ark-space-2);
}

.rdp__memo :deep(.v-field__input) {
    font-size: 0.75rem;
}

/* 表示切替タブ：顧客／履歴／今後の予約（§タブ）。 */
.rdp__tabs {
    display: flex;
    gap: var(--ark-space-2);
    margin-top: var(--ark-space-3);
}

.rdp__tab {
    display: flex;
    flex: 1 1 0;
    min-width: 0;
    align-items: center;
    justify-content: center;
    gap: 4px;
    height: 32px;
    padding: 0 var(--ark-space-2);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.15);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    color: rgba(var(--v-theme-on-surface), 0.78);
    font-size: 0.6875rem;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
}

.rdp__tab:hover {
    border-color: rgba(var(--v-theme-primary), 0.5);
}

.rdp__tab--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
}

.rdp__tab-close {
    margin-left: 2px;
    color: inherit;
}

.rdp__memo-text.is-clamped {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    line-clamp: 4;
    overflow: hidden;
}

.rdp__morebtn,
.rdp__more {
    margin-top: 4px;
    padding: 0;
    border: 0;
    background: none;
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    cursor: pointer;
    text-decoration: none;
}

.rdp__more {
    display: inline-block;
    margin-top: var(--ark-space-2);
}

.rdp__row {
    display: flex;
    align-items: center;
    gap: 4px;
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}

.rdp__row:first-of-type {
    border-top: 0;
}

.rdp__row-main {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    flex: 1 1 auto;
    min-width: 0;
    padding: var(--ark-space-2) var(--ark-space-1);
    text-align: left;
    background: none;
    border: 0;
    cursor: pointer;
}

.rdp__row:hover {
    background: rgba(var(--v-theme-primary), 0.05);
}

.rdp__row--current {
    background: rgba(var(--v-theme-primary), 0.08);
}

/* 日時とステータスは重ならないよう横並びにするが、幅が足りなければ折り返す（§改行）。 */
.rdp__row-top {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px var(--ark-space-2);
    width: 100%;
}

.rdp__row-when {
    font-size: 0.6875rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.rdp__row-service {
    width: 100%;
    font-size: 0.6875rem;
    white-space: normal;
    word-break: break-word;
}

.rdp__row-staff {
    width: 100%;
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
    white-space: normal;
    word-break: break-word;
}

.rdp__row-arrow {
    flex: 0 0 auto;
    color: rgba(var(--v-theme-on-surface), 0.68);
}

.rdp__rebookbtn {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    flex: 0 0 auto;
    height: 24px;
    padding: 0 8px;
    margin-right: 2px;
    border: 0;
    border-radius: 999px;
    background: rgba(var(--v-theme-primary), 0.08);
    color: rgb(var(--v-theme-primary));
    font-size: 0.625rem;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
}

.rdp__rebookbtn:hover {
    background: rgba(var(--v-theme-primary), 0.16);
}

.rdp__nomination {
    display: inline-block;
    margin-left: 4px;
    padding: 0 5px;
    border-radius: var(--ark-radius-sm);
    font-size: 0.625rem;
    font-weight: 800;
    color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.12);
    vertical-align: middle;
}

.rdp__muted {
    margin: 0;
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.rdp__footer {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
}

.rdp__footer .v-btn {
    flex: 1 1 0;
    min-width: 0;
}

.rdp__footer > .v-btn[icon] {
    flex: 0 0 auto;
}

.rdp__menu-danger :deep(.v-list-item-title),
.rdp__menu-danger :deep(.v-icon) {
    color: rgb(var(--v-theme-error));
}
</style>
