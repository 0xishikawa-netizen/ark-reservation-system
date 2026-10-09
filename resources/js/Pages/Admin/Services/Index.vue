<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, EmptyValue, PageHeader, SectionCard, StatusChip, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { formatYenCurrency } from '@/utils/money';
import { useMasterActiveToggle } from '@/composables/masterActive';

defineOptions({ layout: AdminLayout });

// 一覧APIで「有効のみ」を表す値。
const ACTIVE_FILTER_VALUE = 1;

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
    category: string | null;
}

const props = defineProps<{
    trashed?: Array<{ id: number; name: string; deleted_at: string | null }>;
    services: ServiceListItem[];
    filters: Filters;
    categoryOptions?: string[];
    nameOptions?: string[];
}>();

const headers = [
    { title: MESSAGES.mastersUi.services.name, key: 'name' },
    { title: MESSAGES.mastersUi.services.category, key: 'category' },
    { title: MESSAGES.mastersUi.services.analysisCategory, key: 'analysis_category_name' },
    { title: MESSAGES.mastersUi.services.taxCategory, key: 'tax_category_name' },
    { title: MESSAGES.mastersUi.services.duration, key: 'duration_min' },
    { title: MESSAGES.mastersUi.services.price, key: 'price' },
    { title: MESSAGES.mastersUi.services.sortOrder, key: 'sort_order' },
    { title: MESSAGES.mastersUi.services.onlineBooking, key: 'is_online_bookable', sortable: false },
    { title: MESSAGES.mastersUi.services.active, key: 'is_active', sortable: false },
    { title: MESSAGES.mastersUi.services.treatmentStaff, key: 'staff_names', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

const search = ref<string | null>(props.filters.search || null);
const category = ref<string | null>(props.filters.category);
const onlyActive = ref(props.filters.only_active);

const applyFilters = (): void => {
    router.get(
        '/admin/services',
        {
            search: search.value || undefined,
            category: category.value || undefined,
            only_active: onlyActive.value ? ACTIVE_FILTER_VALUE : undefined,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

// 有効/無効の切替（送信中は同じ行を押せない。M-6）
const { isPending: isActivePending, toggle: toggleActive } = useMasterActiveToggle('/admin/services');

</script>

<template>
    <Head :title="MESSAGES.mastersUi.services.title" />

    <PageHeader :title="MESSAGES.mastersUi.services.title" :subtitle="MESSAGES.mastersUi.services.subtitle">
        <template #actions>
            <v-btn color="primary" href="/admin/services/create">
                {{ MESSAGES.mastersUi.services.add }}
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard :title="MESSAGES.mastersUi.services.list" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form
                class="d-flex align-center ga-4 flex-wrap"
                @submit.prevent="applyFilters"
            >
                <!-- 何を入れればよいか分かるよう、検索は候補から選ぶ形にする。 -->
                <v-select
                    v-model="category"
                    :items="categoryOptions ?? []"
                    :label="MESSAGES.mastersUi.services.categoryFilter"
                    clearable
                    hide-details
                    autocomplete="off"
                    max-width="260"
                    @update:model-value="applyFilters"
                />
                <v-autocomplete
                    v-model="search"
                    :items="nameOptions ?? []"
                    :label="MESSAGES.mastersUi.services.nameFilter"
                    clearable
                    hide-details
                    autocomplete="off"
                    max-width="360"
                    @update:model-value="applyFilters"
                />
                <v-switch
                    v-model="onlyActive"
                    :label="MESSAGES.mastersUi.services.publishedOnly"
                    color="primary"
                    hide-details
                    @update:model-value="applyFilters"
                />
            </v-form>
        </div>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="services"
            :sort-by="[{ key: 'sort_order', order: 'asc' }]"
            item-value="id"
            :no-data-text="MESSAGES.mastersUi.services.noData"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-magnify"
                    :title="MESSAGES.mastersUi.services.emptyTitle"
                    :description="MESSAGES.mastersUi.services.emptyDescription"
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
                <template v-if="item.category">{{ item.category }}</template>
                <EmptyValue :label="MESSAGES.common.notSet" v-else />
            </template>
            <template #item.analysis_category_name="{ item }"><template v-if="item.analysis_category_name">{{ item.analysis_category_name }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></template>
            <template #item.tax_category_name="{ item }"><template v-if="item.tax_category_name">{{ item.tax_category_name }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></template>
            <template #item.duration_min="{ item }">
                {{ fillMessage(MESSAGES.mastersUi.services.minutesValue, { minutes: String(item.duration_min) }) }}
            </template>
            <template #item.price="{ item }">
                {{ formatYenCurrency(item.price) }}
            </template>
            <template #item.is_online_bookable="{ item }">
                <StatusChip
                    :status="item.is_online_bookable ? 'active' : 'canceled'"
                    :label="item.is_online_bookable ? MESSAGES.mastersUi.services.available : MESSAGES.mastersUi.services.unavailable"
                />
            </template>
            <template #item.is_active="{ item }">
                <v-switch
                    :model-value="item.is_active"
                    color="primary"
                    hide-details
                    :aria-label="fillMessage(MESSAGES.mastersUi.services.activeState, { name: item.name })"
                    :disabled="isActivePending(item.id)"
                    @update:model-value="toggleActive(item, $event)"
                />
            </template>
            <template #item.staff_names="{ item }">
                <span :title="item.staff_names.join('、')">
                    {{ fillMessage(MESSAGES.mastersUi.services.staffCount, { count: String(item.staff_names.length) }) }}
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
                    {{ MESSAGES.mastersUi.services.edit }}
                </v-btn>
                <MasterDeleteButton type="services" :id="item.id" :name="item.name" :label="MESSAGES.mastersUi.services.masterLabel" />
            </template>
        </v-data-table>
    </SectionCard>
    <TrashedMasterList type="services" :label="MESSAGES.mastersUi.services.masterLabel" :items="trashed ?? []" />
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}

.ark-table-section__filters {
    padding: var(--ark-space-4);
}
</style>
