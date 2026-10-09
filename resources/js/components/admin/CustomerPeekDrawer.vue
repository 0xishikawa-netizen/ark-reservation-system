<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { statusColor } from '@/design/tokens';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

interface RecentReservation {
    id: number;
    starts_at: string;
    service_name: string;
    staff_name: string | null;
    status: string;
    status_label: string;
}

interface Summary {
    profile: {
        user_id: number;
        name: string;
        kana: string | null;
        phone: string | null;
        gender: string | null;
        note: string | null;
        created_at: string | null;
    };
    overview: {
        recent_reservations: RecentReservation[];
        reservation_totals: { total: number; upcoming: number };
        tickets: { active_wallet_count: number; total_available: number };
        membership: {
            plan: { name: string };
            status_label: string;
            status: string;
        } | null;
    };
}

const props = defineProps<{
    modelValue: boolean;
    customerId: number | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

const loading = ref(false);
const error = ref(false);
const summary = ref<Summary | null>(null);
let requestedId: number | null = null;

// 性別は「男 / 女」のみ。未登録・その他は表示しない（推測しない）。
const genderLabel = (value: string | null): string | null => {
    if (value === 'male') return MESSAGES.boardUi.customerPeekDrawer.maleShort;
    if (value === 'female') return MESSAGES.boardUi.customerPeekDrawer.femaleShort;

    return null;
};

const isToday = (isoish: string): boolean => {
    const d = isoish.slice(0, 10);
    const now = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

    return d === today;
};

const formatWhen = (value: string): string => {
    const parsed = new Date(value.replace(' ', 'T'));

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
};

async function load(id: number): Promise<void> {
    loading.value = true;
    error.value = false;
    requestedId = id;

    try {
        const response = await fetch(`/admin/customers/${id}/summary`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = (await response.json()) as Summary;

        // 読み込み中に別の顧客へ切り替わっていたら結果を破棄する。
        if (requestedId === id) {
            summary.value = payload;
        }
    } catch {
        if (requestedId === id) {
            error.value = true;
            summary.value = null;
        }
    } finally {
        if (requestedId === id) {
            loading.value = false;
        }
    }
}

watch(
    () => [props.modelValue, props.customerId] as const,
    ([open, id]) => {
        if (open && id !== null && (summary.value?.profile.user_id !== id)) {
            void load(id);
        }
    },
    { immediate: true },
);

function close(): void {
    emit('update:modelValue', false);
}

function openCustomer(): void {
    if (props.customerId !== null) {
        router.visit(`/admin/customers/${props.customerId}`);
    }
}

function openReservation(id: number): void {
    router.visit(`/admin/reservations/${id}/edit`);
}
</script>

<template>
    <v-navigation-drawer
        :model-value="modelValue"
        location="right"
        temporary
        width="384"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div class="peek">
            <div class="peek__bar">
                <span class="text-subtitle-2 font-weight-bold">{{ MESSAGES.boardUi.customerPeekDrawer.title }}</span>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    :aria-label="MESSAGES.boardUi.customerPeekDrawer.close"
                    @click="close"
                />
            </div>

            <div v-if="loading" class="peek__state">
                <v-progress-circular indeterminate size="28" color="primary" />
            </div>

            <div v-else-if="error" class="peek__state">
                <v-icon icon="mdi-alert-circle-outline" color="error" size="28" />
                <p class="text-body-2 mt-2">{{ MESSAGES.customer.loadFailed }}</p>
                <v-btn
                    v-if="customerId !== null"
                    variant="tonal"
                    size="small"
                    class="mt-3"
                    @click="load(customerId)"
                >
                    {{ MESSAGES.boardUi.customerPeekDrawer.reload }}
                </v-btn>
            </div>

            <template v-else-if="summary">
                <div class="peek__head">
                    <div class="peek__name">
                        <span class="text-h6">{{ summary.profile.name }}</span>
                        <span v-if="summary.profile.kana" class="text-caption text-medium-emphasis">
                            {{ summary.profile.kana }}
                        </span>
                    </div>
                    <div class="peek__tags">
                        <v-chip
                            v-if="genderLabel(summary.profile.gender)"
                            size="small"
                            variant="tonal"
                        >
                            {{ genderLabel(summary.profile.gender) }}
                        </v-chip>
                        <v-chip
                            v-if="summary.overview.membership"
                            size="small"
                            variant="tonal"
                            :color="statusColor(summary.overview.membership.status)"
                        >
                            {{ summary.overview.membership.plan.name }}
                        </v-chip>
                    </div>
                    <div v-if="summary.profile.phone" class="text-body-2 mt-2">
                        <v-icon icon="mdi-phone-outline" size="14" class="mr-1" />{{ summary.profile.phone }}
                    </div>
                </div>

                <div class="peek__stats">
                    <div class="peek__stat">
                        <span class="peek__stat-value">{{ summary.overview.reservation_totals.total }}</span>
                        <span class="peek__stat-label">{{ MESSAGES.boardUi.customerPeekDrawer.visitsAndReservations }}</span>
                    </div>
                    <div class="peek__stat">
                        <span class="peek__stat-value">{{ summary.overview.reservation_totals.upcoming }}</span>
                        <span class="peek__stat-label">{{ MESSAGES.boardUi.customerPeekDrawer.upcoming }}</span>
                    </div>
                    <div class="peek__stat">
                        <span class="peek__stat-value">{{ summary.overview.tickets.total_available }}</span>
                        <span class="peek__stat-label">{{ MESSAGES.boardUi.customerPeekDrawer.ticketBalance }}</span>
                    </div>
                </div>

                <div v-if="summary.profile.note" class="peek__note">
                    <v-icon icon="mdi-note-text-outline" size="14" class="mr-1" />
                    {{ summary.profile.note }}
                </div>

                <div class="peek__section">
                    <span class="peek__section-title">{{ MESSAGES.boardUi.customerPeekDrawer.recentReservations }}</span>
                    <p
                        v-if="summary.overview.recent_reservations.length === 0"
                        class="text-body-2 text-medium-emphasis"
                    >
                        {{ MESSAGES.reservation.noHistory }}
                    </p>
                    <button
                        v-for="reservation in summary.overview.recent_reservations"
                        :key="reservation.id"
                        type="button"
                        class="peek__row"
                        :class="{ 'peek__row--today': isToday(reservation.starts_at) }"
                        @click="openReservation(reservation.id)"
                    >
                        <span class="peek__row-main">
                            <span class="peek__row-when">
                                <span v-if="isToday(reservation.starts_at)" class="peek__today">{{ MESSAGES.boardUi.customerPeekDrawer.today }}</span>
                                {{ formatWhen(reservation.starts_at) }}
                            </span>
                            <span class="peek__row-service">{{ reservation.service_name }}</span>
                            <span v-if="reservation.staff_name" class="peek__row-staff">
                                {{ fillMessage(MESSAGES.boardUi.customerPeekDrawer.staff, { name: reservation.staff_name ?? '' }) }}
                            </span>
                        </span>
                        <v-chip :color="statusColor(reservation.status)" size="x-small" variant="tonal">
                            {{ reservation.status_label }}
                        </v-chip>
                    </button>
                </div>

                <div class="peek__actions">
                    <v-btn
                        block
                        variant="flat"
                        color="primary"
                        prepend-icon="mdi-account-details-outline"
                        @click="openCustomer"
                    >
                        {{ MESSAGES.boardUi.customerPeekDrawer.openCustomer }}
                    </v-btn>
                </div>
            </template>
        </div>
    </v-navigation-drawer>
</template>

<style scoped>
.peek {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: var(--ark-space-4);
    gap: var(--ark-space-4);
}

.peek__bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.peek__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--ark-space-7) var(--ark-space-4);
    text-align: center;
}

.peek__head {
    padding-bottom: var(--ark-space-4);
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.peek__name {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.peek__tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ark-space-2);
    margin-top: var(--ark-space-3);
}

.peek__stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--ark-space-2);
}

.peek__stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--ark-space-3) var(--ark-space-2);
    background: rgba(var(--v-theme-on-surface), 0.04);
    border-radius: var(--ark-radius);
}

.peek__stat-value {
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.2;
}

.peek__stat-label {
    margin-top: 2px;
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.peek__note {
    padding: var(--ark-space-3);
    font-size: 0.8125rem;
    background: rgba(var(--v-theme-warning), 0.1);
    border-radius: var(--ark-radius);
}

.peek__section {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-2);
    flex: 1 1 auto;
    overflow-y: auto;
}

.peek__section-title {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.peek__row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--ark-space-2);
    width: 100%;
    padding: var(--ark-space-3);
    text-align: left;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
    border-radius: var(--ark-radius);
    cursor: pointer;
    transition: border-color 0.15s, background-color 0.15s;
}

.peek__row:hover {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.04);
}

.peek__row--today {
    border-color: rgba(var(--v-theme-primary), 0.5);
}

.peek__row-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.peek__row-when {
    font-size: 0.8125rem;
    font-weight: 700;
}

.peek__today {
    display: inline-block;
    margin-right: 4px;
    padding: 0 5px;
    font-size: 0.625rem;
    font-weight: 800;
    color: #fff;
    background: rgb(var(--v-theme-primary));
    border-radius: 999px;
}

.peek__row-service {
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.82);
}

.peek__row-staff {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.peek__actions {
    padding-top: var(--ark-space-3);
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
</style>
