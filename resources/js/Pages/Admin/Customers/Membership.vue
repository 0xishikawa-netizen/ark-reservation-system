<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateOnly, formatDateTime } from '@/utils/dateFormat';
import { formatYenCurrency } from '@/utils/money';
import { signed } from '@/utils/numberFormat';

defineOptions({ layout: AdminLayout });

interface CustomerSummary {
    user_id: number;
    name: string;
}

interface MembershipDetail {
    id: number;
    plan: {
        id: number;
        name: string;
        price: number;
        usage_count_per_period: number;
        billing_interval: string;
        stripe_price_id: string;
        is_active: boolean;
    };
    status: string;
    status_label: string;
    current_period_start: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    available: number;
    held: number;
    total: number;
    grace_until: string | null;
    started_at: string | null;
    canceled_at: string | null;
    last_synced_at: string | null;
    needs_attention: boolean;
}

interface MembershipHistoryItem {
    id: number;
    membership_id: number;
    type: string;
    type_label: string;
    delta: number;
    period_start: string;
    reservation_id: number | null;
    reason: string | null;
    created_at: string;
}

const props = defineProps<{
    customer: CustomerSummary;
    membership: MembershipDetail | null;
    history: MembershipHistoryItem[];
    can: { adjust: boolean };
}>();

const adjustDialog = ref(false);
const cancelDialog = ref(false);
const operationKey = (): string => crypto.randomUUID();

const adjustForm = useForm({
    delta: 1,
    reason: '',
    operation_key: operationKey(),
});

const cancelForm = useForm({ reason: '' });
const syncForm = useForm({});
const adjustBusinessError = computed(
    () => (adjustForm.errors as Record<string, string>).membership,
);

const historyHeaders = [
    { title: MESSAGES.mastersUi.customerMembership.dateTime, key: 'created_at' },
    { title: 'Membership', key: 'membership_id' },
    { title: MESSAGES.mastersUi.customerMembership.type, key: 'type_label' },
    { title: MESSAGES.mastersUi.customerMembership.delta, key: 'delta' },
    { title: MESSAGES.mastersUi.customerMembership.period, key: 'period_start' },
    { title: MESSAGES.mastersUi.customerMembership.reservationId, key: 'reservation_id' },
    { title: MESSAGES.mastersUi.customerMembership.reason, key: 'reason' },
] as const;

function formatDate(value: string | null): string {
    if (!value) return MESSAGES.common.notSet;

    return formatDateOnly(value, 'dateMedium');
}

function formatDateTimeOrNotRecorded(value: string | null): string {
    if (!value) return MESSAGES.common.notRecorded;

    return formatDateTime(value, 'medium');
}

function openAdjust(): void {
    adjustForm.clearErrors();
    adjustForm.delta = 1;
    adjustForm.reason = '';
    adjustForm.operation_key = operationKey();
    adjustDialog.value = true;
}

function submitAdjust(): void {
    if (!props.membership) return;

    adjustForm.post(`/admin/memberships/${props.membership.id}/adjust`, {
        preserveScroll: true,
        onSuccess: () => { adjustDialog.value = false; },
    });
}

function submitCancel(): void {
    if (!props.membership) return;

    cancelForm.post(`/admin/memberships/${props.membership.id}/cancel-now`, {
        preserveScroll: true,
        onSuccess: () => { cancelDialog.value = false; },
    });
}

function syncMembership(): void {
    if (!props.membership) return;

    syncForm.post(`/admin/memberships/${props.membership.id}/sync`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="fillMessage(MESSAGES.mastersUi.customerMembership.head, { name: customer.name })" />

    <PageHeader :title="MESSAGES.mastersUi.customerMembership.title" :subtitle="customer.name">
        <template #actions>
            <v-btn variant="text" :href="`/admin/customers/${customer.user_id}`">{{ MESSAGES.mastersUi.customerMembership.backToCustomer }}</v-btn>
        </template>
    </PageHeader>

    <EmptyState
        v-if="membership === null"
        icon="mdi-account-credit-card-outline"
        :title="MESSAGES.mastersUi.customerMembership.emptyTitle"
        :description="MESSAGES.mastersUi.customerMembership.emptyDescription"
    />

    <template v-else>
        <v-alert v-if="membership.needs_attention" type="error" variant="tonal" class="mb-4">
            {{ MESSAGES.membership.stripeCheckRequired }}
        </v-alert>

        <div class="ark-page__sections">
            <SectionCard :title="membership.plan.name" class="mb-6" variant="outlined">
                <template #append>
                    <StatusChip :status="membership.status" :label="membership.status_label" />
                </template>
                <div class="d-flex ga-6 flex-wrap mb-5">
                    <div><div class="text-caption">{{ MESSAGES.mastersUi.customerMembership.available }}</div><div class="text-h5 text-primary">{{ fillMessage(MESSAGES.mastersUi.customerMembership.countValue, { count: String(membership.available) }) }}</div></div>
                    <div><div class="text-caption">{{ MESSAGES.mastersUi.customerMembership.held }}</div><div class="text-h6">{{ fillMessage(MESSAGES.mastersUi.customerMembership.countValue, { count: String(membership.held) }) }}</div></div>
                    <div><div class="text-caption">{{ MESSAGES.mastersUi.customerMembership.total }}</div><div class="text-h6">{{ fillMessage(MESSAGES.mastersUi.customerMembership.countValue, { count: String(membership.total) }) }}</div></div>
                </div>

                <v-row>
                    <v-col cols="12" md="6">
                        <v-list lines="two" density="compact">
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.monthlyPrice" :subtitle="formatYenCurrency(membership.plan.price)" />
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.grantedCount" :subtitle="fillMessage(MESSAGES.mastersUi.customerMembership.grantedCountValue, { count: String(membership.plan.usage_count_per_period) })" />
                            <v-list-item
                                :title="MESSAGES.mastersUi.customerMembership.currentPeriod"
                                :subtitle="`${formatDate(membership.current_period_start)} 〜 ${formatDate(membership.current_period_end)}`"
                            />
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.startedAt" :subtitle="formatDateTimeOrNotRecorded(membership.started_at)" />
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.canceledAt" :subtitle="formatDateTimeOrNotRecorded(membership.canceled_at)" />
                        </v-list>
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-list lines="two" density="compact">
                            <v-list-item
                                :title="MESSAGES.mastersUi.customerMembership.cancelAtPeriodEnd"
                                :subtitle="membership.cancel_at_period_end ? MESSAGES.mastersUi.customerMembership.scheduled : MESSAGES.mastersUi.customerMembership.none"
                            />
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.graceUntil" :subtitle="formatDateTimeOrNotRecorded(membership.grace_until)" />
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.lastSyncedAt" :subtitle="formatDateTimeOrNotRecorded(membership.last_synced_at)" />
                            <v-list-item
                                :title="MESSAGES.mastersUi.customerMembership.needsCheck"
                                :subtitle="membership.needs_attention ? MESSAGES.mastersUi.customerMembership.needsAttention : MESSAGES.mastersUi.customerMembership.none"
                            />
                            <v-list-item :title="MESSAGES.mastersUi.customerMembership.stripePriceId" :subtitle="membership.plan.stripe_price_id" />
                        </v-list>
                    </v-col>
                </v-row>
            <v-card-actions v-if="can.adjust" class="pa-4 pt-0 flex-wrap ga-2">
                <v-btn
                    color="primary"
                    variant="outlined"
                    :disabled="membership.status === 'canceled'"
                    @click="openAdjust"
                >
                    {{ MESSAGES.mastersUi.customerMembership.adjustBalance }}
                </v-btn>
                <v-btn
                    color="error"
                    variant="outlined"
                    :disabled="membership.status === 'canceled'"
                    @click="cancelDialog = true"
                >
                    {{ MESSAGES.mastersUi.customerMembership.cancelNow }}
                </v-btn>
                <v-spacer />
                <v-btn color="primary" :loading="syncForm.processing" @click="syncMembership">
                    {{ MESSAGES.mastersUi.customerMembership.syncStripe }}
                </v-btn>
            </v-card-actions>
            </SectionCard>

            <SectionCard class="ark-table-section" :title="MESSAGES.mastersUi.customerMembership.history">
            <v-data-table
                :headers="historyHeaders"
                :items="history"
                item-value="id"
                :no-data-text="MESSAGES.membership.noUsageHistory"
            >
                <template #no-data>
                    <EmptyState
                        icon="mdi-history"
                        :title="MESSAGES.mastersUi.customerMembership.emptyHistoryTitle"
                        :description="MESSAGES.mastersUi.customerMembership.emptyHistoryDescription"
                    />
                </template>
                <template #item.created_at="{ item }">{{ formatDateTimeOrNotRecorded(item.created_at) }}</template>
                <template #item.membership_id="{ item }">#{{ item.membership_id }}</template>
                <template #item.delta="{ item }">{{ signed(item.delta) }}</template>
                <template #item.period_start="{ item }">{{ formatDate(item.period_start) }}</template>
                <template #item.reservation_id="{ item }">
                    {{ item.reservation_id === null ? MESSAGES.common.notLinked : `#${item.reservation_id}` }}
                </template>
                <template #item.reason="{ item }">{{ item.reason || MESSAGES.common.notRecorded }}</template>
            </v-data-table>
            </SectionCard>
        </div>
    </template>

    <v-dialog v-model="adjustDialog" max-width="560">
        <v-card :title="MESSAGES.mastersUi.customerMembership.adjustTitle">
            <v-card-text>
                <v-alert type="warning" variant="tonal" class="mb-4">
                    {{ MESSAGES.common.reauthAudited }}
                </v-alert>
                <v-text-field
                    v-model.number="adjustForm.delta"
                    :label="MESSAGES.mastersUi.customerMembership.adjustCount"
                    type="number"
                    :error-messages="adjustForm.errors.delta"
                    required
                />
                <v-textarea
                    v-model="adjustForm.reason"
                    :label="MESSAGES.mastersUi.customerMembership.reason"
                    maxlength="255"
                    counter
                    :error-messages="adjustForm.errors.reason"
                    required
                />
                <v-alert v-if="adjustBusinessError" type="error" variant="tonal">
                    {{ adjustBusinessError }}
                </v-alert>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="adjustDialog = false">{{ MESSAGES.mastersUi.customerMembership.back }}</v-btn>
                <v-spacer />
                <v-btn color="primary" :loading="adjustForm.processing" @click="submitAdjust">{{ MESSAGES.mastersUi.customerMembership.adjustSubmit }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="cancelDialog" max-width="560">
        <v-card :title="MESSAGES.mastersUi.customerMembership.cancelTitle">
            <v-card-text>
                <v-alert type="error" variant="tonal" class="mb-4">
                    {{ MESSAGES.membership.cancelNowWarning }}
                </v-alert>
                <v-textarea
                    v-model="cancelForm.reason"
                    :label="MESSAGES.mastersUi.customerMembership.reason"
                    maxlength="255"
                    counter
                    :error-messages="cancelForm.errors.reason"
                    required
                />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelDialog = false">{{ MESSAGES.mastersUi.customerMembership.back }}</v-btn>
                <v-spacer />
                <v-btn color="error" :loading="cancelForm.processing" @click="submitCancel">{{ MESSAGES.mastersUi.customerMembership.cancelSubmit }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.ark-page__sections {
    display: grid;
    gap: var(--ark-space-4);
}

.ark-page__sections > * {
    margin-block: 0 !important;
}

.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
