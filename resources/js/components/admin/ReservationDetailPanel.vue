<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, nextTick, ref, watch } from "vue";
import { StatusChip } from "@/components/ark";
import PanelShell from "@/components/admin/PanelShell.vue";
import { firstErrorMessage } from "@/composables/inertiaErrors";
import { MESSAGES } from "@/constants/messages";
import type {
    HistoryRow,
    PanelData,
} from "@/components/admin/panels/reservationDetailTypes";

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

const confirmMode = ref<null | "cancel" | "no_show" | "no_checkout" | "extend">(
    null,
);
// 延長（Task 11-29）：追加する分数と施術。空いていなければサーバーが保存しない。
const extendMinutes = ref<number>(30);
const extendServiceId = ref<number | null>(null);
// 会計なしで来店完了にする理由（Task 11-27）。通常の施術は「来店・会計」で確定する。
const exemptionReason = ref<string | null>(null);
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

    // 予約（施術）の時間を出し、終了後インターバルは別に添える（Task 11-29）。
    const [h, m] = r.ends_at.slice(11, 16).split(":").map(Number);
    const end = h * 60 + m - (r.buffer_min ?? 0);
    const endLabel = `${String(Math.floor(end / 60)).padStart(2, "0")}:${String(end % 60).padStart(2, "0")}`;
    const buffer =
        (r.buffer_min ?? 0) > 0
            ? `（${MESSAGES.visitCompletion.bufferAfter.replace("{min}", String(r.buffer_min))}）`
            : "";

    return `${r.starts_at.slice(11, 16)}〜${endLabel}${buffer}`;
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
                firstErrorMessage(errors) ?? MESSAGES.common.actionFailedReload;
        },
        onFinish: () => {
            actionBusy.value = false;
            confirmMode.value = null;
            cancelReason.value = "";
        },
        onSuccess: () => refetch(),
    });
}

function openVisitEntry(): void {
    const url = data.value?.reservation?.visit_entry_url;
    if (url) {
        actionBusy.value = true;
        router.post(
            url,
            {},
            {
                onFinish: () => {
                    actionBusy.value = false;
                },
            },
        );
    }
}

function openNoCheckout(): void {
    exemptionReason.value = null;
    confirmMode.value = "no_checkout";
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
    } else if (confirmMode.value === "extend") {
        actionBusy.value = true;
        actionError.value = null;
        router.post(
            `/admin/reservations/${id}/extend`,
            {
                minutes: extendMinutes.value,
                service_id: extendServiceId.value,
                version: data.value?.reservation?.version ?? 0,
            },
            {
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
                },
                onSuccess: () => refetch(),
            },
        );
    } else if (
        confirmMode.value === "no_checkout" &&
        exemptionReason.value !== null
    ) {
        runAction(`/admin/reservations/${id}/complete`, {
            exemption_reason: exemptionReason.value,
        });
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
                <p class="rdp__tel">
                    TEL {{ data.customer.phone ?? MESSAGES.common.emptyValue }}
                </p>

                <!-- 表示切替タブ：顧客詳細／履歴／今後の予約（§タブ）。既定は「今回の予約」。 -->
                <div
                    class="rdp__tabs"
                    role="tablist"
                    aria-label="表示の切り替え"
                >
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
                        v-if="!memoEditing && data.can.edit_customer"
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

                    <p v-else class="rdp__memo-empty">
                        {{ MESSAGES.common.emptyValue }}
                    </p>
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
                                指名
                            </span>
                            <span
                                v-if="data.reservation.staff_gender_preference"
                                class="rdp__nomination"
                                :class="`rdp__nomination--${data.reservation.staff_gender_preference}`"
                            >
                                {{
                                    data.reservation.staff_gender_preference ===
                                    "male"
                                        ? "男性希望"
                                        : "女性希望"
                                }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>ブース</dt>
                        <dd>
                            {{
                                data.reservation.booth_name ??
                                MESSAGES.common.emptyValue
                            }}
                        </dd>
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
                        v-if="data.reservation.visit_entry_url"
                        color="primary"
                        variant="flat"
                        size="small"
                        prepend-icon="mdi-cash-register"
                        data-testid="open-visit-entry"
                        :loading="actionBusy"
                        @click="openVisitEntry"
                    >
                        来店・会計
                    </v-btn>
                    <v-btn
                        v-if="data.can.manage"
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
                        v-if="data.can.manage"
                        color="primary"
                        variant="flat"
                        size="small"
                        @click="emit('create')"
                    >
                        新規予約
                    </v-btn>
                </template>

                <!-- 閲覧だけの権限（一般スタッフ）では操作が無いので「…」自体を出さない。 -->
                <v-menu
                    v-if="data.can.manage || data.reservation?.payment"
                    v-model="moreMenuOpen"
                    location="top end"
                >
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
                            v-if="data.can.manage"
                            prepend-icon="mdi-calendar-plus-outline"
                            title="新規予約"
                            @click="emit('create')"
                        />
                        <v-list-item
                            v-if="data.reservation?.can_extend"
                            prepend-icon="mdi-clock-plus-outline"
                            :title="MESSAGES.schedule.extend"
                            data-testid="extend-reservation"
                            @click="
                                extendMinutes = 30;
                                extendServiceId = null;
                                confirmMode = 'extend';
                            "
                        />
                        <v-list-item
                            v-if="data.reservation?.can_complete"
                            prepend-icon="mdi-check-circle-outline"
                            :title="MESSAGES.visitCompletion.noCheckoutMenu"
                            data-testid="complete-without-checkout"
                            @click="openNoCheckout"
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
                            : confirmMode === "no_checkout"
                              ? MESSAGES.visitCompletion.noCheckoutTitle
                              : confirmMode === "extend"
                                ? MESSAGES.schedule.extendTitle
                                : "無断キャンセルにしますか？"
                    }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-2">
                        {{ data.customer?.name ?? "" }} ／
                        {{ fmtDay(data.reservation.date) }} {{ timeRange }}
                        <br />{{ data.reservation.service_name }}
                    </div>
                    <template v-if="confirmMode === 'extend'">
                        <p class="text-body-2 mb-2">
                            {{ MESSAGES.schedule.extendHint }}
                        </p>
                        <v-btn-toggle
                            v-model="extendMinutes"
                            mandatory
                            density="compact"
                            variant="outlined"
                            class="mb-3"
                            data-testid="extend-minutes"
                        >
                            <v-btn
                                v-for="minutes in [15, 30, 45, 60]"
                                :key="minutes"
                                :value="minutes"
                                size="small"
                                >+{{ minutes }}分</v-btn
                            >
                        </v-btn-toggle>
                        <v-select
                            v-model="extendServiceId"
                            :items="data.reservation.extension_services ?? []"
                            item-title="name"
                            item-value="id"
                            :label="MESSAGES.schedule.extendService"
                            :placeholder="data.reservation.service_name"
                            density="compact"
                            variant="outlined"
                            clearable
                            hide-details
                            data-testid="extend-service"
                        />
                    </template>
                    <template v-if="confirmMode === 'no_checkout'">
                        <p class="text-body-2 mb-2">
                            {{ MESSAGES.visitCompletion.noCheckoutHint }}
                        </p>
                        <v-radio-group
                            v-model="exemptionReason"
                            density="compact"
                            hide-details
                            data-testid="exemption-reasons"
                        >
                            <v-radio
                                v-for="(label, value) in MESSAGES
                                    .visitCompletion.exemptionReasons"
                                :key="value"
                                :label="label"
                                :value="value"
                            />
                        </v-radio-group>
                    </template>
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
                        :disabled="
                            confirmMode === 'no_checkout' &&
                            exemptionReason === null
                        "
                        @click="submitConfirm"
                    >
                        {{
                            confirmMode === "cancel"
                                ? "キャンセルする"
                                : confirmMode === "no_checkout"
                                  ? MESSAGES.visitCompletion.noCheckoutSubmit
                                  : confirmMode === "extend"
                                    ? MESSAGES.schedule.extendSubmit
                                    : "無断キャンセルにする"
                        }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </PanelShell>
</template>

<style
    scoped
    src="@/components/admin/panels/ReservationDetailPanel.css"
></style>
