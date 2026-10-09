<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyValue, PageHeader, SectionCard } from '@/components/ark';
import { realEmail } from '@/utils/placeholderEmail';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateTime } from '@/utils/dateFormat';

defineOptions({ layout: AdminLayout });

// 顧客一覧の先頭ページ。
const FIRST_PAGE = 1;
// ページネーションに表示する最大ページ数。
const PAGINATION_VISIBLE_PAGES = 7;

interface CustomerListItem {
    user_id: number;
    name: string;
    kana: string;
    email: string;
    email_verified_at: string | null;
    created_via: string;
    created_at: string;
}

interface CustomerPaginator {
    data: CustomerListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    customers: CustomerPaginator;
    filters: { q: string };
}>();

const headers = [
    { title: MESSAGES.mastersUi.customers.name, key: 'name' },
    { title: MESSAGES.mastersUi.customers.kana, key: 'kana' },
    { title: MESSAGES.mastersUi.customers.email, key: 'email' },
    { title: MESSAGES.mastersUi.customers.verified, key: 'email_verified_at', sortable: false },
    { title: MESSAGES.mastersUi.customers.source, key: 'created_via' },
    { title: MESSAGES.mastersUi.customers.registeredAt, key: 'created_at' },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

const search = ref<string | null>(props.filters.q);

const visitIndex = (page = FIRST_PAGE): void => {
    router.get(
        '/admin/customers',
        {
            q: search.value || undefined,
            page: page > FIRST_PAGE ? page : undefined,
        },
        { preserveState: true, replace: true },
    );
};

const createdViaLabel = (value: string): string => {
    const labels: Record<string, string> = {
        web: 'Web',
        admin: MESSAGES.mastersUi.customers.adminSource,
        migration: MESSAGES.mastersUi.customers.migrationSource,
    };

    return labels[value] ?? value;
};
</script>

<template>
    <Head :title="MESSAGES.mastersUi.customers.title" />

    <PageHeader :title="MESSAGES.mastersUi.customers.title" :subtitle="fillMessage(MESSAGES.mastersUi.customers.total, { count: String(customers.total) })" />

    <SectionCard :title="MESSAGES.mastersUi.customers.list" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form class="d-flex align-center ga-4" @submit.prevent="visitIndex()">
                <v-text-field
                    v-model="search"
                    :label="MESSAGES.mastersUi.customers.searchLabel"
                    :placeholder="MESSAGES.mastersUi.customers.searchPlaceholder"
                    clearable
                    hide-details
                    class="filter-grid__search"
                />
                <v-btn type="submit" variant="tonal">{{ MESSAGES.mastersUi.customers.search }}</v-btn>
            </v-form>
            <p class="text-caption text-medium-emphasis mt-3 mb-0">
                {{ MESSAGES.customer.phoneExactMatch }}
            </p>
        </div>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="customers.data"
            item-value="user_id"
            :items-per-page="-1"
            hide-default-footer
            :no-data-text="MESSAGES.mastersUi.customers.noData"
        >
            <template #item.name="{ item }">
                <v-btn
                    variant="text"
                    color="primary"
                    class="px-0"
                    :href="`/admin/customers/${item.user_id}`"
                >
                    {{ item.name }}
                </v-btn>
            </template>
            <template #item.email="{ item }">
                <template v-if="realEmail(item.email)">{{ realEmail(item.email) }}</template>
                <EmptyValue v-else :label="MESSAGES.common.notEntered" />
            </template>
            <template #item.kana="{ item }">
                <template v-if="item.kana">{{ item.kana }}</template>
                <EmptyValue v-else :label="MESSAGES.common.notEntered" />
            </template>
            <template #item.email_verified_at="{ item }">
                <v-chip
                    :color="item.email_verified_at ? 'success' : 'warning'"
                    size="small"
                >
                    {{ item.email_verified_at ? MESSAGES.mastersUi.customers.verificationDone : MESSAGES.mastersUi.customers.verificationPending }}
                </v-chip>
            </template>
            <template #item.created_via="{ item }">
                {{ createdViaLabel(item.created_via) }}
            </template>
            <template #item.created_at="{ item }">
                {{ formatDateTime(item.created_at, 'dateMedium') }}
            </template>
            <template #item.actions="{ item }">
                <v-btn
                    size="small"
                    variant="tonal"
                    color="primary"
                    append-icon="mdi-chevron-right"
                    :href="`/admin/customers/${item.user_id}`"
                >
                    {{ MESSAGES.customer.openDetail }}
                </v-btn>
            </template>
        </v-data-table>

        <template v-if="customers.last_page > 1">
            <v-divider />
            <div class="d-flex justify-center pa-4">
                <v-pagination
                    :model-value="customers.current_page"
                    :length="customers.last_page"
                    :total-visible="PAGINATION_VISIBLE_PAGES"
                    density="comfortable"
                    rounded="circle"
                    @update:model-value="visitIndex"
                />
            </div>
        </template>
    </SectionCard>
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}

.ark-table-section__filters {
    padding: var(--ark-space-4);
}

.filter-grid__search {
    width: 360px;
    flex: 0 0 auto;
}

@media (max-width: 600px) {
    .filter-grid__search {
        width: 100%;
    }
}
</style>
