<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface ServiceListItem {
    id: number;
    name: string;
    category: string | null;
    analysis_category_name: string | null;
    tax_category_name: string | null;
    duration_min: number;
    price: number;
    is_online_bookable: boolean;
    requires_staff: boolean;
    color: string;
    is_active: boolean;
    sort_order: number;
    staff_names: string[];
}

interface Filters {
    search: string;
    only_active: boolean;
}

const props = defineProps<{
    services: ServiceListItem[];
    filters: Filters;
}>();

const headers = [
    { title: 'メニュー名', key: 'name' },
    { title: '集計分類', key: 'analysis_category_name' },
    { title: '税区分', key: 'tax_category_name' },
    { title: '所要時間', key: 'duration_min', sortable: false },
    { title: '価格', key: 'price', sortable: false },
    { title: 'オンライン予約', key: 'is_online_bookable', sortable: false },
    { title: '有効', key: 'is_active', sortable: false },
    { title: '施術スタッフ', key: 'staff_names', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

const search = ref<string | null>(props.filters.search);
const onlyActive = ref(props.filters.only_active);

const applyFilters = (): void => {
    router.get(
        '/admin/services',
        {
            search: search.value || undefined,
            only_active: onlyActive.value ? 1 : undefined,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const toggleActive = (service: ServiceListItem): void => {
    router.patch(
        `/admin/services/${service.id}/active`,
        { active: !service.is_active },
        { preserveScroll: true },
    );
};

const formatPrice = (price: number): string =>
    new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(price);
</script>

<template>
    <Head title="メニュー" />

    <PageHeader title="メニュー" subtitle="予約メニューの公開状態と担当スタッフを管理します。">
        <template #actions>
            <v-btn color="primary" href="/admin/services/create">
                メニューを追加
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard title="メニュー一覧" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form
                class="d-flex align-center ga-4 flex-wrap"
                @submit.prevent="applyFilters"
            >
                <v-text-field
                    v-model="search"
                    label="メニュー名・カテゴリを検索"
                    clearable
                    hide-details
                    max-width="420"
                />
                <v-switch
                    v-model="onlyActive"
                    label="公開中のメニューのみ"
                    color="primary"
                    hide-details
                    @update:model-value="applyFilters"
                />
                <v-btn type="submit" variant="tonal">検索</v-btn>
            </v-form>
        </div>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="services"
            item-value="id"
            no-data-text="該当するメニューはありません。"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-magnify"
                    title="該当するメニューはありません"
                    description="検索条件を変更するか、新しいメニューを追加してください。"
                />
            </template>
            <template #item.name="{ item }">
                <div class="d-flex align-center ga-2">
                    <span
                        class="d-inline-block rounded-circle"
                        :style="{
                            backgroundColor: item.color,
                            height: '16px',
                            width: '16px',
                        }"
                    />
                    <span>{{ item.name }}</span>
                </div>
            </template>
            <template #item.category="{ item }">
                {{ item.category || '—' }}
            </template>
            <template #item.analysis_category_name="{ item }">{{ item.analysis_category_name || '未設定' }}</template>
            <template #item.tax_category_name="{ item }">{{ item.tax_category_name || '未設定' }}</template>
            <template #item.duration_min="{ item }">
                {{ item.duration_min }}分
            </template>
            <template #item.price="{ item }">
                {{ formatPrice(item.price) }}
            </template>
            <template #item.is_online_bookable="{ item }">
                <StatusChip
                    :status="item.is_online_bookable ? 'active' : 'canceled'"
                    :label="item.is_online_bookable ? '可' : '不可'"
                />
            </template>
            <template #item.is_active="{ item }">
                <v-switch
                    :model-value="item.is_active"
                    color="primary"
                    hide-details
                    :aria-label="`${item.name}の有効状態`"
                    @click.stop="toggleActive(item)"
                />
            </template>
            <template #item.staff_names="{ item }">
                <span :title="item.staff_names.join('、')">
                    {{ item.staff_names.length }}名
                </span>
            </template>
            <template #item.actions="{ item }">
                <v-btn
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    :href="`/admin/services/${item.id}/edit`"
                >
                    編集
                </v-btn>
            </template>
        </v-data-table>
    </SectionCard>
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}

.ark-table-section__filters {
    padding: var(--ark-space-4);
}
</style>
