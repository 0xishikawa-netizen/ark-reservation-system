<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

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

defineProps<{
    wallets: TicketWalletItem[];
    history: TicketHistoryItem[];
}>();

const statusLabels: Record<string, string> = {
    active: '有効',
    exhausted: '残数なし',
    expired: '期限切れ',
};

const transactionLabels: Record<string, string> = {
    PURCHASE: '購入',
    GRANT: '付与',
    RESERVE_HOLD: '予約確保',
    RESERVE_RELEASE: '予約解放',
    CONSUME: '消化',
    REVOKE: '取消',
    EXPIRE: '失効',
    ADJUST: '調整',
};

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));
}

function signed(delta: number): string {
    return delta > 0 ? `+${delta}` : String(delta);
}
</script>

<template>
    <Head title="回数券" />

    <PageHeader title="回数券" subtitle="回数券のご購入は店頭でご相談ください。" />

    <section aria-labelledby="wallets-heading" class="mb-8">
        <h2 id="wallets-heading" class="text-h6 mb-3">保有回数券</h2>
        <EmptyState
            v-if="wallets.length === 0"
            icon="mdi-ticket-outline"
            title="保有している回数券はありません"
            description="回数券のご購入については、店頭でスタッフへご相談ください。"
        />
        <div v-else class="d-flex flex-column ga-3">
            <SectionCard v-for="wallet in wallets" :key="wallet.id" :title="wallet.product_name">
                <template #append>
                    <StatusChip
                        :status="wallet.status"
                        :label="statusLabels[wallet.status] ?? wallet.status"
                    />
                </template>

                <div class="ticket-counts mb-4">
                    <div>
                        <div class="text-caption text-medium-emphasis">利用可能</div>
                        <div class="text-h5 text-primary">{{ wallet.available }}回</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">予約中</div>
                        <div class="text-h6">{{ wallet.held }}回</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">合計</div>
                        <div class="text-h6">{{ wallet.total }}回</div>
                    </div>
                </div>
                <div class="text-body-2">
                    有効期限：{{ formatDate(wallet.expires_at) }}
                </div>
            </SectionCard>
        </div>
    </section>

    <section aria-labelledby="history-heading">
        <h2 id="history-heading" class="text-h6 mb-3">利用履歴</h2>
        <EmptyState
            v-if="history.length === 0"
            icon="mdi-history"
            title="利用履歴はありません"
            description="回数券の購入や利用があると、こちらに履歴が表示されます。"
        />
        <v-card v-else variant="outlined">
            <v-list lines="two">
                <template v-for="(item, index) in history" :key="item.id">
                    <v-list-item>
                        <template #title>
                            <span class="font-weight-medium">
                                {{ transactionLabels[item.type] ?? item.type }}
                            </span>
                            <span
                                class="ml-2 font-weight-bold"
                                :class="item.delta > 0 ? 'text-success' : 'text-error'"
                            >
                                {{ signed(item.delta) }}回
                            </span>
                        </template>
                        <template #subtitle>
                            {{ formatDateTime(item.created_at) }}
                        </template>
                    </v-list-item>
                    <v-divider v-if="index < history.length - 1" />
                </template>
            </v-list>
        </v-card>
    </section>
</template>

<style scoped>
.ticket-counts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}
</style>
