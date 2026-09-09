<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface AuditLogRow {
    id: number;
    created_at: string;
    action: string;
    entity_type: string | null;
    entity_id: string | null;
    summary: string;
    ip: string | null;
    actor_name: string | null;
}

interface AuditLogPaginator {
    data: AuditLogRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface AuditLogFilters {
    action: string | null;
    actor_user_id: number | null;
    entity_type: string | null;
    date_from: string | null;
    date_to: string | null;
}

const props = defineProps<{
    logs: AuditLogPaginator;
    actions: string[];
    filters: AuditLogFilters;
}>();

const action = ref<string | null>(props.filters.action);
const entityType = ref<string | null>(props.filters.entity_type);
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

const visitIndex = (page = 1): void => {
    router.get(
        '/admin/system/audit-logs',
        {
            action: action.value ?? undefined,
            actor_user_id: props.filters.actor_user_id ?? undefined,
            entity_type: entityType.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, replace: true },
    );
};

const clearFilters = (): void => {
    action.value = null;
    entityType.value = null;
    dateFrom.value = '';
    dateTo.value = '';
    router.get(
        '/admin/system/audit-logs',
        {},
        { preserveState: true, replace: true },
    );
};

const formatDateTime = (value: string): string =>
    new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));

const entityLabel = (item: AuditLogRow): string =>
    `${item.entity_type ?? '—'}${item.entity_id ? ` #${item.entity_id}` : ''}`;
</script>

<template>
    <Head title="監査ログ" />

    <PageHeader title="監査ログ" :subtitle="`全 ${logs.total} 件`" />

    <SectionCard class="ark-audit-section">
            <v-form class="filter-grid" @submit.prevent="visitIndex()">
                <v-select
                    v-model="action"
                    :items="actions"
                    label="アクション"
                    clearable
                    hide-details
                />
                <v-text-field
                    v-model="entityType"
                    label="対象種別"
                    clearable
                    hide-details
                />
                <v-text-field
                    v-model="dateFrom"
                    type="date"
                    label="開始日"
                    hide-details
                />
                <v-text-field
                    v-model="dateTo"
                    type="date"
                    label="終了日"
                    hide-details
                />
                <div class="d-flex ga-2 align-center">
                    <v-btn type="submit" variant="tonal">適用</v-btn>
                    <v-btn variant="text" @click="clearFilters">クリア</v-btn>
                </div>
            </v-form>

        <v-divider />

        <v-table>
            <thead>
                <tr>
                    <th>日時</th>
                    <th>操作者</th>
                    <th>アクション</th>
                    <th>対象</th>
                    <th>概要</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="log in logs.data" :key="log.id">
                    <td>{{ formatDateTime(log.created_at) }}</td>
                    <td>{{ log.actor_name ?? 'システム' }}</td>
                    <td>{{ log.action }}</td>
                    <td>{{ entityLabel(log) }}</td>
                    <td>{{ log.summary }}</td>
                    <td>{{ log.ip ?? '—' }}</td>
                </tr>
                <tr v-if="logs.data.length === 0">
                    <td colspan="6">
                        <EmptyState
                            icon="mdi-text-box-search-outline"
                            title="該当する監査ログはありません"
                            description="検索条件を変更すると、ほかの操作記録を確認できます。"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>

        <v-divider v-if="logs.last_page > 1" />

        <v-card-actions v-if="logs.last_page > 1" class="justify-center pa-4">
            <v-pagination
                :model-value="logs.current_page"
                :length="logs.last_page"
                :total-visible="7"
                @update:model-value="visitIndex"
            />
        </v-card-actions>
    </SectionCard>
</template>

<style scoped>
.ark-audit-section :deep(.v-card-text) {
    display: grid;
    gap: var(--ark-space-4);
    padding: var(--ark-space-4) 0 0;
}

.filter-grid,
.ark-audit-section :deep(.v-card-actions) {
    margin-inline: var(--ark-space-4);
}

.filter-grid {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) repeat(2, minmax(150px, 1fr)) auto;
    gap: var(--ark-space-4);
}

@media (max-width: 1100px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
