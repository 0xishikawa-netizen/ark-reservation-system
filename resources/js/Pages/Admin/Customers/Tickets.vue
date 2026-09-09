<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

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
    { title: '商品', key: 'product_name' },
    { title: '付与数', key: 'purchased_count' },
    { title: '利用可能', key: 'available' },
    { title: '予約確保', key: 'held' },
    { title: '合計', key: 'total' },
    { title: '有効期限', key: 'expires_at' },
    { title: '状態', key: 'status' },
    { title: '', key: 'actions', sortable: false },
] as const;

const historyHeaders = [
    { title: '日時', key: 'created_at' },
    { title: 'Wallet', key: 'wallet_id' },
    { title: '種別', key: 'type' },
    { title: '増減', key: 'delta' },
    { title: '予約ID', key: 'reservation_id' },
    { title: '理由', key: 'reason' },
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

const formatDate = (value: string): string =>
    new Intl.DateTimeFormat('ja-JP', { dateStyle: 'medium' }).format(new Date(value));

const formatDateTime = (value: string): string =>
    new Intl.DateTimeFormat('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value.replace(' ', 'T')));

const statusLabel = (status: string): string => {
    const labels: Record<string, string> = {
        active: '有効',
        exhausted: '残数なし',
        expired: '期限切れ',
    };

    return labels[status] ?? status;
};

const transactionLabel = (type: string): string => {
    const labels: Record<string, string> = {
        PURCHASE: '購入',
        GRANT: '付与',
        REVOKE: '取消',
        RESERVE_HOLD: '予約確保',
        RESERVE_RELEASE: '予約確保解除',
        CONSUME: '消化',
        EXPIRE: '期限切れ',
        ADJUST: '調整',
    };

    return labels[type] ?? type;
};

const signed = (delta: number): string => (delta > 0 ? `+${delta}` : String(delta));
</script>

<template>
    <Head :title="`${customer.name}の回数券`" />

    <PageHeader title="顧客回数券" :subtitle="customer.name">
        <template #actions>
            <v-btn
                v-if="can.grant"
                color="primary"
                :disabled="ticketProducts.length === 0"
                @click="openGrant"
            >
                付与
            </v-btn>
        </template>
    </PageHeader>

    <div class="ark-page__sections">
        <v-alert v-if="can.grant && ticketProducts.length === 0" type="info" class="mb-4">
            付与できる有効な回数券商品がありません。
        </v-alert>

        <SectionCard class="ark-table-section mb-6" title="保有回数券">
        <v-data-table
            :headers="walletHeaders"
            :items="wallets"
            item-value="id"
            no-data-text="保有している回数券はありません。"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-ticket-confirmation-outline"
                    title="保有している回数券はありません"
                    description="回数券を付与すると、こちらに残数と有効期限が表示されます。"
                />
            </template>
            <template #item.purchased_count="{ item }">{{ item.purchased_count }}回</template>
            <template #item.expires_at="{ item }">{{ formatDate(item.expires_at) }}</template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :label="statusLabel(item.status)" />
            </template>
            <template #item.actions="{ item }">
                <div v-if="can.grant" class="d-flex ga-1 justify-end">
                    <v-btn size="small" variant="text" @click="openRevoke(item)">取消</v-btn>
                    <v-btn size="small" variant="text" @click="openAdjust(item)">調整</v-btn>
                </div>
            </template>
        </v-data-table>
        </SectionCard>

        <SectionCard class="ark-table-section" title="履歴">
        <v-data-table
            :headers="historyHeaders"
            :items="history"
            item-value="id"
            no-data-text="回数券履歴はありません。"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-history"
                    title="回数券履歴はありません"
                    description="付与や利用、調整を行うと、こちらに履歴が記録されます。"
                />
            </template>
            <template #item.created_at="{ item }">{{ formatDateTime(item.created_at) }}</template>
            <template #item.wallet_id="{ item }">#{{ item.wallet_id }}</template>
            <template #item.type="{ item }">{{ transactionLabel(item.type) }}</template>
            <template #item.delta="{ item }">{{ signed(item.delta) }}</template>
            <template #item.reservation_id="{ item }">
                {{ item.reservation_id === null ? '—' : `#${item.reservation_id}` }}
            </template>
            <template #item.reason="{ item }">{{ item.reason || '—' }}</template>
        </v-data-table>
        <v-card-actions>
            <v-btn variant="text" :href="`/admin/customers/${customer.user_id}`">
                顧客詳細へ戻る
            </v-btn>
        </v-card-actions>
        </SectionCard>
    </div>

    <v-dialog v-model="grantDialog" max-width="560">
        <v-card title="回数券を付与">
            <v-card-text>
                <v-form @submit.prevent="submitGrant">
                    <v-select
                        v-model="grantForm.ticket_product_id"
                        label="回数券商品"
                        :items="ticketProducts"
                        item-title="name"
                        item-value="id"
                        :error-messages="grantForm.errors.ticket_product_id"
                        required
                    />
                    <v-text-field
                        v-model.number="grantForm.count"
                        label="付与回数"
                        type="number"
                        min="1"
                        max="999"
                        clearable
                        :error-messages="grantForm.errors.count"
                    />
                    <v-textarea
                        v-model="grantForm.reason"
                        label="理由"
                        maxlength="255"
                        counter
                        :error-messages="grantForm.errors.reason"
                        required
                    />
                </v-form>
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn variant="text" @click="grantDialog = false">キャンセル</v-btn>
                <v-btn color="primary" :loading="grantForm.processing" @click="submitGrant">
                    付与する
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="revokeDialog" max-width="560">
        <v-card :title="`${selectedWallet?.product_name ?? ''}を取り消す`">
            <v-card-text>
                <v-alert v-if="revokeBusinessError" type="error" class="mb-4">
                    {{ revokeBusinessError }}
                </v-alert>
                <v-text-field
                    v-model.number="revokeForm.count"
                    label="取消回数"
                    type="number"
                    min="1"
                    :error-messages="revokeForm.errors.count"
                    required
                />
                <v-textarea
                    v-model="revokeForm.reason"
                    label="理由"
                    maxlength="255"
                    counter
                    :error-messages="revokeForm.errors.reason"
                    required
                />
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn variant="text" @click="revokeDialog = false">キャンセル</v-btn>
                <v-btn color="error" :loading="revokeForm.processing" @click="submitRevoke">
                    取り消す
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="adjustDialog" max-width="560">
        <v-card :title="`${selectedWallet?.product_name ?? ''}の残数を調整`">
            <v-card-text>
                <v-alert v-if="adjustBusinessError" type="error" class="mb-4">
                    {{ adjustBusinessError }}
                </v-alert>
                <v-text-field
                    v-model.number="adjustForm.delta"
                    label="調整数（減らす場合は負数）"
                    type="number"
                    :error-messages="adjustForm.errors.delta"
                    required
                />
                <v-textarea
                    v-model="adjustForm.reason"
                    label="理由"
                    maxlength="255"
                    counter
                    :error-messages="adjustForm.errors.reason"
                    required
                />
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn variant="text" @click="adjustDialog = false">キャンセル</v-btn>
                <v-btn color="primary" :loading="adjustForm.processing" @click="submitAdjust">
                    調整する
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
