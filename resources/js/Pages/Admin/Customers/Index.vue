<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyValue, PageHeader, SectionCard } from '@/components/ark';
import { realEmail } from '@/utils/placeholderEmail';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

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
    { title: '氏名', key: 'name' },
    { title: 'カナ', key: 'kana' },
    { title: 'メール', key: 'email' },
    { title: '認証済', key: 'email_verified_at', sortable: false },
    { title: '登録経路', key: 'created_via' },
    { title: '登録日', key: 'created_at' },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

const search = ref<string | null>(props.filters.q);

const visitIndex = (page = 1): void => {
    router.get(
        '/admin/customers',
        {
            q: search.value || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, replace: true },
    );
};

const formatDate = (value: string): string =>
    new Intl.DateTimeFormat('ja-JP', { dateStyle: 'medium' }).format(
        new Date(value.replace(' ', 'T')),
    );

const createdViaLabel = (value: string): string => {
    const labels: Record<string, string> = {
        web: 'Web',
        admin: '管理画面',
        migration: '移行',
    };

    return labels[value] ?? value;
};
</script>

<template>
    <Head title="顧客" />

    <PageHeader title="顧客" :subtitle="`全 ${customers.total} 件`" />

    <SectionCard title="顧客一覧" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form class="d-flex align-center ga-4" @submit.prevent="visitIndex()">
                <v-text-field
                    v-model="search"
                    label="顧客検索"
                    placeholder="氏名・カナ・メール（部分一致）／電話（完全一致）"
                    clearable
                    hide-details
                    class="filter-grid__search"
                />
                <v-btn type="submit" variant="tonal">検索</v-btn>
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
            no-data-text="該当する顧客はいません。"
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
                    {{ item.email_verified_at ? '済' : '未' }}
                </v-chip>
            </template>
            <template #item.created_via="{ item }">
                {{ createdViaLabel(item.created_via) }}
            </template>
            <template #item.created_at="{ item }">
                {{ formatDate(item.created_at) }}
            </template>
            <template #item.actions="{ item }">
                <v-btn
                    size="small"
                    variant="tonal"
                    color="primary"
                    append-icon="mdi-chevron-right"
                    :href="`/admin/customers/${item.user_id}`"
                >
                    詳細
                </v-btn>
            </template>
        </v-data-table>

        <template v-if="customers.last_page > 1">
            <v-divider />
            <div class="d-flex justify-center pa-4">
                <v-pagination
                    :model-value="customers.current_page"
                    :length="customers.last_page"
                    :total-visible="7"
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
