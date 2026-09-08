<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

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
    { title: '', key: 'actions', sortable: false },
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

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">顧客</h1>
        <span class="text-body-2 text-medium-emphasis">全 {{ customers.total }} 件</span>
    </div>

    <v-card>
        <v-card-text>
            <v-form class="d-flex align-center ga-4" @submit.prevent="visitIndex()">
                <v-text-field
                    v-model="search"
                    label="顧客検索"
                    placeholder="氏名・カナ・メール（部分一致）／電話（完全一致）"
                    clearable
                    hide-details
                    max-width="640"
                />
                <v-btn type="submit" variant="tonal">検索</v-btn>
            </v-form>
            <p class="text-caption text-medium-emphasis mt-3 mb-0">
                電話番号はハイフンの有無を問わず完全一致で検索します。部分一致には対応していません。
            </p>
        </v-card-text>

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
                    variant="text"
                    :href="`/admin/customers/${item.user_id}`"
                >
                    詳細
                </v-btn>
            </template>
        </v-data-table>

        <v-card-actions v-if="customers.last_page > 1" class="justify-center pa-4">
            <v-pagination
                :model-value="customers.current_page"
                :length="customers.last_page"
                @update:model-value="visitIndex"
            />
        </v-card-actions>
    </v-card>
</template>
