<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateObject, formatDateTime } from '@/utils/dateFormat';
import { signed } from '@/utils/numberFormat';

defineOptions({ layout: AdminLayout });

interface CustomerSummary {
    user_id: number;
    name: string;
}

interface TicketWalletItem {
    id: number;
    product_name: string;
    purchased_count: number;
    available: number;
    held: number;
    total: number;
    balance_cache: number;
    expires_at: string;
    status: string;
}

interface TicketHistoryItem {
    id: number;
    wallet_id: number;
    type: string;
    delta: number;
    reservation_id: number | null;
    reason: string | null;
    created_at: string;
}

interface TicketProductOption {
    id: number;
    name: string;
    total_count: number;
}

const props = defineProps<{
    customer: CustomerSummary;
    wallets: TicketWalletItem[];
    history: TicketHistoryItem[];
    ticketProducts: TicketProductOption[];
    can: { grant: boolean };
}>();

const walletHeaders = [
    { title: MESSAGES.mastersUi.customerTickets.product, key: 'product_name' },
    { title: MESSAGES.mastersUi.customerTickets.grantedCount, key: 'purchased_count' },
    { title: MESSAGES.mastersUi.customerTickets.available, key: 'available' },
    { title: MESSAGES.mastersUi.customerTickets.held, key: 'held' },
    { title: MESSAGES.mastersUi.customerTickets.total, key: 'total' },
    { title: MESSAGES.mastersUi.customerTickets.expiresAt, key: 'expires_at' },
    { title: MESSAGES.mastersUi.customerTickets.status, key: 'status' },
    { title: '', key: 'actions', sortable: false },
] as const;

const historyHeaders = [
    { title: MESSAGES.mastersUi.customerTickets.dateTime, key: 'created_at' },
    { title: 'Wallet', key: 'wallet_id' },
    { title: MESSAGES.mastersUi.customerTickets.type, key: 'type' },
    { title: MESSAGES.mastersUi.customerTickets.delta, key: 'delta' },
    { title: MESSAGES.mastersUi.customerTickets.reservationId, key: 'reservation_id' },
    { title: MESSAGES.mastersUi.customerTickets.reason, key: 'reason' },
] as const;

const grantDialog = ref(false);
const revokeDialog = ref(false);
const adjustDialog = ref(false);
const selectedWallet = ref<TicketWalletItem | null>(null);

const operationKey = (): string => crypto.randomUUID();

const grantForm = useForm({
    ticket_product_id: null as number | null,
    count: null as number | null,
    reason: '',
    operation_key: operationKey(),
});

const revokeForm = useForm({
    count: 1,
    reason: '',
    operation_key: operationKey(),
});

const adjustForm = useForm({
    delta: 1,
    reason: '',
    operation_key: operationKey(),
});

const revokeBusinessError = computed(
    () => (revokeForm.errors as Record<string, string>).ticket,
);
const adjustBusinessError = computed(
    () => (adjustForm.errors as Record<string, string>).ticket,
);

watch(
    () => grantForm.ticket_product_id,
    (productId) => {
        const product = props.ticketProducts.find((item) => item.id === productId);

        if (product) grantForm.count = product.total_count;
    },
);

const openGrant = (): void => {
    grantForm.clearErrors();
    grantForm.operation_key = operationKey();
    grantDialog.value = true;
};

const openRevoke = (wallet: TicketWalletItem): void => {
    selectedWallet.value = wallet;
    revokeForm.clearErrors();
    revokeForm.count = 1;
    revokeForm.reason = '';
    revokeForm.operation_key = operationKey();
    revokeDialog.value = true;
};

const openAdjust = (wallet: TicketWalletItem): void => {
    selectedWallet.value = wallet;
    adjustForm.clearErrors();
    adjustForm.delta = 1;
    adjustForm.reason = '';
    adjustForm.operation_key = operationKey();
    adjustDialog.value = true;
};

const submitGrant = (): void => {
    grantForm.post(`/admin/customers/${props.customer.user_id}/tickets/grant`, {
        preserveScroll: true,
        onSuccess: () => {
            grantDialog.value = false;
            grantForm.reset();
            grantForm.operation_key = operationKey();
        },
    });
};

const submitRevoke = (): void => {
    if (!selectedWallet.value) return;

    revokeForm.post(`/admin/ticket-wallets/${selectedWallet.value.id}/revoke`, {
        preserveScroll: true,
        onSuccess: () => {
            revokeDialog.value = false;
            selectedWallet.value = null;
        },
    });
};

const submitAdjust = (): void => {
    if (!selectedWallet.value) return;

    adjustForm.post(`/admin/ticket-wallets/${selectedWallet.value.id}/adjust`, {
        preserveScroll: true,
        onSuccess: () => {
            adjustDialog.value = false;
            selectedWallet.value = null;
        },
    });
};

const statusLabel = (status: string): string => {
    const labels: Record<string, string> = MESSAGES.mastersUi.customerTickets.statuses;

    return labels[status] ?? status;
};

const transactionLabel = (type: string): string => {
    const labels: Record<string, string> = MESSAGES.mastersUi.customerTickets.transactionTypes;

    return labels[type] ?? type;
};

</script>

<template>
    <Head :title="fillMessage(MESSAGES.mastersUi.customerTickets.head, { name: customer.name })" />

    <PageHeader :title="MESSAGES.mastersUi.customerTickets.title" :subtitle="customer.name">
        <template #actions>
            <v-btn
                v-if="can.grant"
                color="primary"
                :disabled="ticketProducts.length === 0"
                @click="openGrant"
            >
                {{ MESSAGES.mastersUi.customerTickets.grant }}
            </v-btn>
        </template>
    </PageHeader>

    <div class="ark-page__sections">
        <v-alert v-if="can.grant && ticketProducts.length === 0" type="info" class="mb-4">
            {{ MESSAGES.ticket.noneGrantable }}
        </v-alert>

        <SectionCard class="ark-table-section mb-6" :title="MESSAGES.mastersUi.customerTickets.walletTitle">
        <v-data-table
            :headers="walletHeaders"
            :items="wallets"
            item-value="id"
            :no-data-text="MESSAGES.mastersUi.customerTickets.noWallets"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-ticket-confirmation-outline"
                    :title="MESSAGES.mastersUi.customerTickets.emptyWalletsTitle"
                    :description="MESSAGES.mastersUi.customerTickets.emptyWalletsDescription"
                />
            </template>
            <template #item.purchased_count="{ item }">{{ fillMessage(MESSAGES.mastersUi.customerTickets.countValue, { count: String(item.purchased_count) }) }}</template>
            <template #item.expires_at="{ item }">{{ formatDateObject(new Date(item.expires_at), 'dateMedium') }}</template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :label="statusLabel(item.status)" />
            </template>
            <template #item.actions="{ item }">
                <div v-if="can.grant" class="d-flex ga-1 justify-end">
                    <v-btn size="small" variant="text" @click="openRevoke(item)">{{ MESSAGES.mastersUi.customerTickets.revoke }}</v-btn>
                    <v-btn size="small" variant="text" @click="openAdjust(item)">{{ MESSAGES.mastersUi.customerTickets.adjust }}</v-btn>
                </div>
            </template>
        </v-data-table>
        </SectionCard>

        <SectionCard class="ark-table-section" :title="MESSAGES.mastersUi.customerTickets.history">
        <v-data-table
            :headers="historyHeaders"
            :items="history"
            item-value="id"
            :no-data-text="MESSAGES.mastersUi.customerTickets.noHistory"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-history"
                    :title="MESSAGES.mastersUi.customerTickets.emptyHistoryTitle"
                    :description="MESSAGES.mastersUi.customerTickets.emptyHistoryDescription"
                />
            </template>
            <template #item.created_at="{ item }">{{ formatDateTime(item.created_at, 'medium') }}</template>
            <template #item.wallet_id="{ item }">#{{ item.wallet_id }}</template>
            <template #item.type="{ item }">{{ transactionLabel(item.type) }}</template>
            <template #item.delta="{ item }">{{ signed(item.delta) }}</template>
            <template #item.reservation_id="{ item }">
                {{ item.reservation_id === null ? MESSAGES.common.notLinked : `#${item.reservation_id}` }}
            </template>
            <template #item.reason="{ item }">{{ item.reason || MESSAGES.common.notRecorded }}</template>
        </v-data-table>
        <v-card-actions>
            <v-btn variant="text" :href="`/admin/customers/${customer.user_id}`">
                {{ MESSAGES.mastersUi.customerTickets.backToCustomer }}
            </v-btn>
        </v-card-actions>
        </SectionCard>
    </div>

    <v-dialog v-model="grantDialog" max-width="560">
        <v-card :title="MESSAGES.mastersUi.customerTickets.grantTitle">
            <v-card-text>
                <v-form @submit.prevent="submitGrant">
                    <v-select
                        v-model="grantForm.ticket_product_id"
                        :label="MESSAGES.mastersUi.customerTickets.productField"
                        :items="ticketProducts"
                        item-title="name"
                        item-value="id"
                        :error-messages="grantForm.errors.ticket_product_id"
                        required
                    />
                    <v-text-field
                        v-model.number="grantForm.count"
                        :label="MESSAGES.mastersUi.customerTickets.grantCount"
                        type="number"
                        min="1"
                        max="999"
                        clearable
                        :error-messages="grantForm.errors.count"
                    />
                    <v-textarea
                        v-model="grantForm.reason"
                        :label="MESSAGES.mastersUi.customerTickets.reason"
                        maxlength="255"
                        counter
                        :error-messages="grantForm.errors.reason"
                        required
                    />
                </v-form>
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn variant="text" @click="grantDialog = false">{{ MESSAGES.mastersUi.customerTickets.cancel }}</v-btn>
                <v-btn color="primary" :loading="grantForm.processing" @click="submitGrant">
                    {{ MESSAGES.mastersUi.customerTickets.grantSubmit }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="revokeDialog" max-width="560">
        <v-card :title="fillMessage(MESSAGES.mastersUi.customerTickets.revokeTitle, { name: selectedWallet?.product_name ?? '' })">
            <v-card-text>
                <v-alert v-if="revokeBusinessError" type="error" class="mb-4">
                    {{ revokeBusinessError }}
                </v-alert>
                <v-text-field
                    v-model.number="revokeForm.count"
                    :label="MESSAGES.mastersUi.customerTickets.revokeCount"
                    type="number"
                    min="1"
                    :error-messages="revokeForm.errors.count"
                    required
                />
                <v-textarea
                    v-model="revokeForm.reason"
                    :label="MESSAGES.mastersUi.customerTickets.reason"
                    maxlength="255"
                    counter
                    :error-messages="revokeForm.errors.reason"
                    required
                />
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn variant="text" @click="revokeDialog = false">{{ MESSAGES.mastersUi.customerTickets.cancel }}</v-btn>
                <v-btn color="error" :loading="revokeForm.processing" @click="submitRevoke">
                    {{ MESSAGES.mastersUi.customerTickets.revokeSubmit }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="adjustDialog" max-width="560">
        <v-card :title="fillMessage(MESSAGES.mastersUi.customerTickets.adjustTitle, { name: selectedWallet?.product_name ?? '' })">
            <v-card-text>
                <v-alert v-if="adjustBusinessError" type="error" class="mb-4">
                    {{ adjustBusinessError }}
                </v-alert>
                <v-text-field
                    v-model.number="adjustForm.delta"
                    :label="MESSAGES.mastersUi.customerTickets.adjustCount"
                    type="number"
                    :error-messages="adjustForm.errors.delta"
                    required
                />
                <v-textarea
                    v-model="adjustForm.reason"
                    :label="MESSAGES.mastersUi.customerTickets.reason"
                    maxlength="255"
                    counter
                    :error-messages="adjustForm.errors.reason"
                    required
                />
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn variant="text" @click="adjustDialog = false">{{ MESSAGES.mastersUi.customerTickets.cancel }}</v-btn>
                <v-btn color="primary" :loading="adjustForm.processing" @click="submitAdjust">
                    {{ MESSAGES.mastersUi.customerTickets.adjustSubmit }}
                </v-btn>
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
