<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface BoothListItem {
    id: number;
    name: string;
    sort_order: number;
    is_active: boolean;
}

interface Filters {
    search: string;
}

const props = defineProps<{
    booths: BoothListItem[];
    filters: Filters;
}>();

const headers = [
    { title: 'ブース名', key: 'name' },
    { title: '表示順', key: 'sort_order' },
    { title: '有効', key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false },
] as const;

const search = ref<string | null>(props.filters.search);

const applyFilters = (): void => {
    router.get(
        '/admin/booths',
        { search: search.value || undefined },
        { preserveState: true, replace: true },
    );
};

const toggleActive = (booth: BoothListItem): void => {
    router.patch(
        `/admin/booths/${booth.id}/active`,
        { active: !booth.is_active },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="ブース" />

    <PageHeader title="ブース" subtitle="予約で使用するブースと表示順を管理します。">
        <template #actions>
            <v-btn color="primary" href="/admin/booths/create">
                ブースを追加
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard title="ブース一覧" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form class="d-flex align-center ga-4" @submit.prevent="applyFilters">
                <v-text-field
                    v-model="search"
                    label="ブース名を検索"
                    clearable
                    hide-details
                    max-width="420"
                />
                <v-btn type="submit" variant="tonal">検索</v-btn>
            </v-form>
        </div>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="booths"
            item-value="id"
            no-data-text="該当するブースはありません。"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-door-open"
                    title="該当するブースはありません"
                    description="検索条件を変更するか、新しいブースを追加してください。"
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
            <template #item.actions="{ item }">
                <v-btn
                    size="small"
                    variant="text"
                    :href="`/admin/booths/${item.id}/edit`"
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
