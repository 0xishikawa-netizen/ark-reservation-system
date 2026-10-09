<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateOnly, formatDateTime } from '@/utils/dateFormat';
import { signed } from '@/utils/numberFormat';

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

const statusLabels: Record<string, string> = MESSAGES.customerUi.tickets.statuses;

const transactionLabels: Record<string, string> = MESSAGES.customerUi.tickets.transactions;

function times(count: number | string): string {
    return fillMessage(MESSAGES.customerUi.format.times, { count: String(count) });
}
</script>

<template>
    <Head :title="MESSAGES.customerUi.tickets.title" />

    <PageHeader :title="MESSAGES.customerUi.tickets.title" :subtitle="MESSAGES.customerUi.tickets.subtitle" />

    <section aria-labelledby="wallets-heading" class="mb-8">
        <h2 id="wallets-heading" class="text-h6 mb-3">{{ MESSAGES.customerUi.tickets.wallets }}</h2>
        <EmptyState
            v-if="wallets.length === 0"
            icon="mdi-ticket-outline"
            :title="MESSAGES.customerUi.tickets.noWalletsTitle"
            :description="MESSAGES.customerUi.tickets.noWalletsDescription"
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
                        <div class="text-caption text-medium-emphasis">{{ MESSAGES.customerUi.tickets.available }}</div>
                        <div class="text-h5 text-primary">{{ times(wallet.available) }}</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">{{ MESSAGES.customerUi.tickets.held }}</div>
                        <div class="text-h6">{{ times(wallet.held) }}</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">{{ MESSAGES.customerUi.tickets.total }}</div>
                        <div class="text-h6">{{ times(wallet.total) }}</div>
                    </div>
                </div>
                <div class="text-body-2">
                    {{ fillMessage(MESSAGES.customerUi.tickets.expiresAt, { date: formatDateOnly(wallet.expires_at, 'dateLong') }) }}
                </div>
            </SectionCard>
        </div>
    </section>

    <section aria-labelledby="history-heading">
        <h2 id="history-heading" class="text-h6 mb-3">{{ MESSAGES.customerUi.tickets.history }}</h2>
        <EmptyState
            v-if="history.length === 0"
            icon="mdi-history"
            :title="MESSAGES.customerUi.tickets.noHistoryTitle"
            :description="MESSAGES.customerUi.tickets.noHistoryDescription"
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
                                {{ times(signed(item.delta)) }}
                            </span>
                        </template>
                        <template #subtitle>
                            {{ formatDateTime(item.created_at, 'numeric') }}
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
