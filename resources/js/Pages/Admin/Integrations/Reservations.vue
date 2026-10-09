<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { EmptyValue, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

interface ActionableRow {
    id: number;
    reservation_id: number;
    operation: string;
    status: string;
    attempts: number;
    error: string | null;
    at: string | null;
}

interface ProviderRow {
    key: string;
    label: string;
    is_active: boolean;
    status: string;
    health: string;
    last_inbound_at: string | null;
    last_outbound_at: string | null;
    last_reconcile_at: string | null;
    pending_outbox: number;
    failed_outbox: number;
    stuck_processing: number;
    open_conflicts: number;
    needs_attention: number;
    actionable: ActionableRow[];
}

interface EventRow {
    id: number;
    provider: string;
    direction: string;
    operation: string;
    status: string;
    external_id: string | null;
    error_category: string | null;
    error_code: string | null;
    attempt: number;
    at: string | null;
}

defineProps<{
    active_provider: string | null;
    providers: ProviderRow[];
    recent_events: EventRow[];
}>();

const M = MESSAGES.reportsUi.integrations;

const canManage = Boolean(usePage().props.auth?.can?.integrationsManage);

// StatusChip は semantic key を色に写像する。日本語ステータスを key へ変換。
function providerStatusKey(status: string): string {
    return (
        {
            正常: 'confirmed',
            要確認: 'grace',
            停止: 'canceled',
            未設定: 'canceled',
        } as Record<string, string>
    )[status] ?? 'expired';
}

function retry(row: ActionableRow): void {
    if (!canManage) {
        return;
    }
    router.post(
        `/admin/integrations/reservations/outbox/${row.id}/retry`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="M.title" />

    <PageHeader :title="M.title" :subtitle="M.subtitle" />

    <SectionCard :title="M.providers" class="mb-6">
        <v-alert
            v-if="!active_provider"
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-4"
        >
            {{ MESSAGES.integration.noneActive }}
        </v-alert>

        <div class="provider-grid">
            <v-card v-for="p in providers" :key="p.key" variant="outlined" class="pa-4">
                <div class="d-flex align-center justify-space-between mb-2">
                    <span class="text-subtitle-1 font-weight-medium">{{ p.label }}</span>
                    <StatusChip :status="providerStatusKey(p.status)" :label="p.status" />
                </div>
                <v-list density="compact" class="bg-transparent">
                    <v-list-item :title="M.lastInbound" :subtitle="p.last_inbound_at ?? MESSAGES.common.notSynced" />
                    <v-list-item :title="M.lastOutbound" :subtitle="p.last_outbound_at ?? MESSAGES.common.notSynced" />
                    <v-list-item :title="M.lastReconcile" :subtitle="p.last_reconcile_at ?? MESSAGES.common.notReconciled" />
                    <v-list-item :title="M.pendingOutbox" :subtitle="String(p.pending_outbox)" />
                    <v-list-item
                        :title="M.failedConflicts"
                        :subtitle="`${p.failed_outbox} / ${p.open_conflicts}`"
                    />
                    <v-list-item
                        v-if="p.stuck_processing > 0"
                        :title="M.stuckProcessing"
                        :subtitle="fillMessage(M.stuckCount, { count: String(p.stuck_processing) })"
                    />
                </v-list>
                <v-chip v-if="p.needs_attention > 0" color="warning" size="small" class="mt-2">
                    {{ fillMessage(M.needsAttentionCount, { count: String(p.needs_attention) }) }}
                </v-chip>
            </v-card>
        </div>
    </SectionCard>

    <SectionCard
        v-for="p in providers.filter((x) => x.actionable.length > 0)"
        :key="`act-${p.key}`"
        :title="fillMessage(M.actionableQueue, { label: p.label })"
        class="mb-6"
    >
        <v-alert
            v-if="!canManage"
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-3"
        >
            {{ MESSAGES.integration.retryForbidden }}
        </v-alert>
        <v-table density="compact">
            <thead>
                <tr>
                    <th>{{ M.datetime }}</th>
                    <th>{{ M.reservationId }}</th>
                    <th>{{ M.operation }}</th>
                    <th>{{ M.status }}</th>
                    <th>{{ M.attempts }}</th>
                    <th>{{ M.error }}</th>
                    <th class="text-right">{{ M.operation }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in p.actionable" :key="row.id">
                    <td>{{ row.at ?? MESSAGES.common.notRecorded }}</td>
                    <td>{{ row.reservation_id }}</td>
                    <td>{{ row.operation }}</td>
                    <td><StatusChip :status="row.status" :label="row.status" /></td>
                    <td>{{ row.attempts }}</td>
                    <td class="text-medium-emphasis"><template v-if="row.error">{{ row.error }}</template><EmptyValue v-else /></td>
                    <td class="text-right">
                        <v-btn
                            size="small"
                            variant="tonal"
                            color="primary"
                            :disabled="!canManage"
                            @click="retry(row)"
                        >
                            {{ M.retry }}
                        </v-btn>
                    </td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>

    <SectionCard :title="M.recentEvents">
        <v-alert
            v-if="recent_events.length === 0"
            type="info"
            variant="tonal"
            density="comfortable"
        >
            {{ MESSAGES.integration.noSyncHistory }}
        </v-alert>
        <v-table v-else density="compact">
            <thead>
                <tr>
                    <th>{{ M.datetime }}</th>
                    <th>{{ M.service }}</th>
                    <th>{{ M.direction }}</th>
                    <th>{{ M.operation }}</th>
                    <th>{{ M.result }}</th>
                    <th>{{ M.externalId }}</th>
                    <th>{{ M.note }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="e in recent_events" :key="e.id">
                    <td>{{ e.at ?? MESSAGES.common.notRecorded }}</td>
                    <td>{{ e.provider }}</td>
                    <td>{{ e.direction === 'inbound' ? M.inbound : M.outbound }}</td>
                    <td>{{ e.operation }}</td>
                    <td><StatusChip :status="e.status" :label="e.status" /></td>
                    <td>{{ e.external_id ?? MESSAGES.common.notLinked }}</td>
                    <td>
                        <span v-if="e.error_code" class="text-medium-emphasis">
                            {{ fillMessage(M.errorDetail, { category: e.error_category ?? '', code: e.error_code ?? '', attempt: String(e.attempt) }) }}
                        </span>
                        <EmptyValue v-else />
                    </td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>
</template>

<style scoped>
.provider-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
    gap: 1rem;
}
</style>
