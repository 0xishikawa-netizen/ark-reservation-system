<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

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
    <Head title="外部予約連携" />

    <PageHeader title="外部予約連携" subtitle="外部予約サービスとの同期状態を確認できます。" />

    <SectionCard title="連携サービス" class="mb-6">
        <v-alert
            v-if="!active_provider"
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-4"
        >
            現在、実動する外部予約連携はありません（Mock のみ利用可能）。
        </v-alert>

        <div class="provider-grid">
            <v-card v-for="p in providers" :key="p.key" variant="outlined" class="pa-4">
                <div class="d-flex align-center justify-space-between mb-2">
                    <span class="text-subtitle-1 font-weight-medium">{{ p.label }}</span>
                    <StatusChip :status="providerStatusKey(p.status)" :label="p.status" />
                </div>
                <v-list density="compact" class="bg-transparent">
                    <v-list-item title="最終 Inbound" :subtitle="p.last_inbound_at ?? '—'" />
                    <v-list-item title="最終 Outbound" :subtitle="p.last_outbound_at ?? '—'" />
                    <v-list-item title="最終 Reconcile" :subtitle="p.last_reconcile_at ?? '—'" />
                    <v-list-item title="送信待ち" :subtitle="String(p.pending_outbox)" />
                    <v-list-item
                        title="要対応（失敗 / 競合）"
                        :subtitle="`${p.failed_outbox} / ${p.open_conflicts}`"
                    />
                    <v-list-item
                        v-if="p.stuck_processing > 0"
                        title="処理中で滞留"
                        :subtitle="`${p.stuck_processing} 件（15 分以上）`"
                    />
                </v-list>
                <v-chip v-if="p.needs_attention > 0" color="warning" size="small" class="mt-2">
                    要確認 {{ p.needs_attention }} 件
                </v-chip>
            </v-card>
        </div>
    </SectionCard>

    <SectionCard
        v-for="p in providers.filter((x) => x.actionable.length > 0)"
        :key="`act-${p.key}`"
        :title="`要対応の送信キュー — ${p.label}`"
        class="mb-6"
    >
        <v-alert
            v-if="!canManage"
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-3"
        >
            再送には「連携管理」権限が必要です。
        </v-alert>
        <v-table density="compact">
            <thead>
                <tr>
                    <th>日時</th>
                    <th>予約ID</th>
                    <th>操作</th>
                    <th>状態</th>
                    <th>試行</th>
                    <th>エラー</th>
                    <th class="text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in p.actionable" :key="row.id">
                    <td>{{ row.at ?? '—' }}</td>
                    <td>{{ row.reservation_id }}</td>
                    <td>{{ row.operation }}</td>
                    <td><StatusChip :status="row.status" :label="row.status" /></td>
                    <td>{{ row.attempts }}</td>
                    <td class="text-medium-emphasis">{{ row.error ?? '—' }}</td>
                    <td class="text-right">
                        <v-btn
                            size="small"
                            variant="tonal"
                            color="primary"
                            :disabled="!canManage"
                            @click="retry(row)"
                        >
                            再送
                        </v-btn>
                    </td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>

    <SectionCard title="最近の同期履歴">
        <v-alert
            v-if="recent_events.length === 0"
            type="info"
            variant="tonal"
            density="comfortable"
        >
            同期履歴はまだありません。
        </v-alert>
        <v-table v-else density="compact">
            <thead>
                <tr>
                    <th>日時</th>
                    <th>サービス</th>
                    <th>方向</th>
                    <th>操作</th>
                    <th>結果</th>
                    <th>外部ID</th>
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="e in recent_events" :key="e.id">
                    <td>{{ e.at ?? '—' }}</td>
                    <td>{{ e.provider }}</td>
                    <td>{{ e.direction === 'inbound' ? '取込' : '送信' }}</td>
                    <td>{{ e.operation }}</td>
                    <td><StatusChip :status="e.status" :label="e.status" /></td>
                    <td>{{ e.external_id ?? '—' }}</td>
                    <td>
                        <span v-if="e.error_code" class="text-medium-emphasis">
                            {{ e.error_category }} / {{ e.error_code }}（試行 {{ e.attempt }}）
                        </span>
                        <span v-else>—</span>
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
