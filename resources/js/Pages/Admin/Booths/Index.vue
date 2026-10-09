<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, PageHeader, SectionCard, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { useMasterActiveToggle } from '@/composables/masterActive';

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
    trashed?: Array<{ id: number; name: string; deleted_at: string | null }>;
    booths: BoothListItem[];
    filters: Filters;
}>();

const headers = [
    { title: MESSAGES.mastersUi.booths.name, key: 'name' },
    { title: MESSAGES.mastersUi.booths.sortOrder, key: 'sort_order' },
    { title: MESSAGES.mastersUi.booths.active, key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

const search = ref<string | null>(props.filters.search);

const applyFilters = (): void => {
    router.get(
        '/admin/booths',
        { search: search.value || undefined },
        { preserveState: true, replace: true },
    );
};

// 有効/無効の切替（送信中は同じ行を押せない。M-6）
const { isPending: isActivePending, toggle: toggleActive } = useMasterActiveToggle('/admin/booths');
</script>

<template>
    <Head :title="MESSAGES.mastersUi.booths.title" />

    <PageHeader :title="MESSAGES.mastersUi.booths.title" :subtitle="MESSAGES.mastersUi.booths.subtitle">
        <template #actions>
            <v-btn color="primary" href="/admin/booths/create">
                {{ MESSAGES.mastersUi.booths.add }}
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard :title="MESSAGES.mastersUi.booths.list" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form class="d-flex align-center ga-4" @submit.prevent="applyFilters">
                <v-text-field
                    v-model="search"
                    :label="MESSAGES.mastersUi.booths.searchLabel"
                    clearable
                    hide-details
                    max-width="420"
                />
                <v-btn type="submit" variant="tonal">{{ MESSAGES.mastersUi.booths.search }}</v-btn>
            </v-form>
        </div>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="booths"
            item-value="id"
            :no-data-text="MESSAGES.mastersUi.booths.noData"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-door-open"
                    :title="MESSAGES.mastersUi.booths.emptyTitle"
                    :description="MESSAGES.mastersUi.booths.emptyDescription"
                />
            </template>
            <template #item.is_active="{ item }">
                <v-switch
                    :model-value="item.is_active"
                    color="primary"
                    hide-details
                    :aria-label="fillMessage(MESSAGES.mastersUi.booths.activeState, { name: item.name })"
                    :disabled="isActivePending(item.id)"
                    @update:model-value="toggleActive(item, $event)"
                />
            </template>
            <template #item.actions="{ item }">
                <v-btn
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    :href="`/admin/booths/${item.id}/edit`"
                >
                    {{ MESSAGES.mastersUi.booths.edit }}
                </v-btn>
                <MasterDeleteButton type="booths" :id="item.id" :name="item.name" :label="MESSAGES.mastersUi.booths.masterLabel" />
            </template>
        </v-data-table>
    </SectionCard>
    <TrashedMasterList type="booths" :label="MESSAGES.mastersUi.booths.masterLabel" :items="trashed ?? []" />
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}

.ark-table-section__filters {
    padding: var(--ark-space-4);
}
</style>
