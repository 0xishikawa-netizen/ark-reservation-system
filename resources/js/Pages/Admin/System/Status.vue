<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { statusColor } from '@/design/tokens';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

type StripeMode = 'test' | 'live' | 'placeholder' | 'missing';

interface FailedJobsStatus {
    count: number;
    oldest_failed_at: string | null;
}

interface DbSizeStatus {
    latest_mb: number | null;
    captured_on: string | null;
    alert_mb: number;
    over_threshold: boolean;
}

interface UnmeasuredStatus {
    measured: false;
    note: string;
}

interface LastBackupStatus {
    measured: boolean;
    newest_at: string | null;
    size_bytes: number | null;
    age_hours: number | null;
    healthy: boolean;
    note: string;
}

interface SystemStatusProps {
    stripe_mode: StripeMode;
    reservation_authority: string;
    external_gateway: string;
    failed_jobs: FailedJobsStatus;
    stale_pending_reservations: number;
    db_size: DbSizeStatus;
    reconcile: UnmeasuredStatus;
    last_backup: LastBackupStatus;
    real_stripe_test_mode_qa: 'incomplete';
    membership_production_readiness: 'not_ready';
}

const props = defineProps<SystemStatusProps>();

const M = MESSAGES.reportsUi.systemStatus;

const stripeChipColor = computed<string>(() => {
    if (props.stripe_mode === 'test') {
        return 'green';
    }

    if (props.stripe_mode === 'live') {
        return 'red';
    }

    return 'grey';
});

const stripeLabel = computed<string>(() => {
    const labels: Record<StripeMode, string> = {
        test: 'Test',
        live: 'Live',
        placeholder: M.stripePlaceholder,
        missing: MESSAGES.common.notSet,
    };

    return labels[props.stripe_mode];
});

const qaLabel = computed<string>(() =>
    props.real_stripe_test_mode_qa === 'incomplete' ? M.qaIncomplete : M.qaComplete,
);

const membershipReadinessLabel = computed<string>(() =>
    props.membership_production_readiness === 'not_ready' ? M.notReady : M.ready,
);

const backupSize = (bytes: number): string => {
    const megabytes = bytes / 1024 / 1024;

    return `${megabytes.toLocaleString('ja-JP', { maximumFractionDigits: 1 })} MB`;
};
</script>

<template>
    <Head :title="M.title" />

    <PageHeader
        :title="M.title"
        :subtitle="M.subtitle"
    />

    <v-alert
        type="warning"
        :color="statusColor('failed')"
        variant="tonal"
        prominent
        class="mb-6"
    >
        {{ fillMessage(M.readinessBanner, { qa: qaLabel, membership: membershipReadinessLabel }) }}
    </v-alert>

    <v-row>
        <v-col cols="12" md="6">
            <SectionCard :title="M.stripeMode" variant="outlined" height="100%">
                <template #append>
                    <StatusChip
                        :status="stripe_mode === 'test' ? 'active' : stripe_mode === 'live' ? 'failed' : 'canceled'"
                        :label="stripe_mode === 'test' ? MESSAGES.system.backupOk : stripe_mode === 'live' ? MESSAGES.system.backupWarning : MESSAGES.system.backupUnmeasured"
                    />
                </template>
                <div class="text-body-1 font-weight-medium" :class="`text-${stripeChipColor}`">
                    {{ stripeLabel }}
                </div>
                <div v-if="stripe_mode === 'live'" class="text-error font-weight-bold mt-2">
                    {{ M.liveForbidden }}
                </div>
            </SectionCard>
        </v-col>

        <v-col cols="12" md="6">
            <SectionCard :title="M.reservationIntegration" variant="outlined" height="100%">
                <template #append>
                    <StatusChip status="active" :label="MESSAGES.system.backupOk" />
                </template>
                    <div class="mb-3">
                        <div class="text-caption text-medium-emphasis">{{ M.reservationAuthority }}</div>
                        <div>{{ reservation_authority }}</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">{{ M.externalGateway }}</div>
                        <div>{{ external_gateway }}</div>
                    </div>
            </SectionCard>
        </v-col>

        <v-col cols="12" md="6">
            <SectionCard :title="M.failedJobs" variant="outlined" height="100%">
                <template #append>
                    <StatusChip
                        :status="failed_jobs.count === 0 ? 'active' : 'failed'"
                        :label="failed_jobs.count === 0 ? MESSAGES.system.backupOk : MESSAGES.system.backupWarning"
                    />
                </template>
                    <div class="text-h5 mb-2">{{ fillMessage(M.count, { count: String(failed_jobs.count) }) }}</div>
                    <div class="text-body-2 text-medium-emphasis">
                        {{ fillMessage(M.oldestFailedAt, { at: failed_jobs.oldest_failed_at ?? M.none }) }}
                    </div>
                    <v-btn
                        href="/admin/system/failed-jobs"
                        variant="text"
                        color="primary"
                        class="mt-3 px-0"
                    >
                        {{ M.viewFailedJobs }}
                    </v-btn>
            </SectionCard>
        </v-col>

        <v-col cols="12" md="6">
            <SectionCard :title="M.stalePending" variant="outlined" height="100%">
                <template #append>
                    <StatusChip
                        :status="stale_pending_reservations === 0 ? 'active' : 'grace'"
                        :label="stale_pending_reservations === 0 ? MESSAGES.system.backupOk : MESSAGES.system.backupWarning"
                    />
                </template>
                <div class="text-h5">
                    {{ fillMessage(M.count, { count: String(stale_pending_reservations) }) }}
                </div>
            </SectionCard>
        </v-col>

        <v-col cols="12" md="6">
            <SectionCard :title="M.dbSize" variant="outlined" height="100%">
                <template #append>
                    <StatusChip
                        :status="db_size.over_threshold ? 'failed' : db_size.latest_mb === null ? 'canceled' : 'active'"
                        :label="db_size.over_threshold ? MESSAGES.system.backupWarning : db_size.latest_mb === null ? MESSAGES.system.backupUnmeasured : MESSAGES.system.backupOk"
                    />
                </template>
                    <div class="text-h6">
                        <template v-if="db_size.latest_mb === null">{{ MESSAGES.system.backupUnmeasured }}</template>
                        <template v-else>{{ db_size.latest_mb }} MB</template>
                        <span class="text-body-1 text-medium-emphasis">
                            {{ fillMessage(M.threshold, { mb: String(db_size.alert_mb) }) }}
                        </span>
                    </div>
                    <div v-if="db_size.captured_on" class="text-body-2 text-medium-emphasis mt-2">
                        {{ fillMessage(M.capturedOn, { date: db_size.captured_on ?? '' }) }}
                    </div>
            </SectionCard>
        </v-col>

        <v-col cols="12" md="6">
            <SectionCard :title="M.reconcile" variant="outlined" height="100%">
                <template #append>
                    <StatusChip status="canceled" :label="MESSAGES.system.backupUnmeasured" />
                </template>
                {{ reconcile.note }}
            </SectionCard>
        </v-col>

        <v-col cols="12" md="6">
            <SectionCard :title="M.lastBackup" variant="outlined" height="100%">
                <template #append>
                    <StatusChip
                        :status="!last_backup.measured ? 'canceled' : last_backup.healthy ? 'active' : 'failed'"
                        :label="!last_backup.measured ? MESSAGES.system.backupUnmeasured : last_backup.healthy ? MESSAGES.system.backupOk : MESSAGES.system.backupWarning"
                    />
                </template>
                <div>{{ last_backup.note }}</div>
                <div v-if="last_backup.newest_at" class="text-body-2 text-medium-emphasis mt-2">
                    {{ MESSAGES.system.backupNewestAt }}: {{ last_backup.newest_at }}<br>
                    {{ MESSAGES.system.backupSize }}: {{ backupSize(last_backup.size_bytes ?? 0) }} ／
                    {{ MESSAGES.system.backupAge }}: {{ last_backup.age_hours ?? 0 }} {{ MESSAGES.system.backupHours }}
                </div>
            </SectionCard>
        </v-col>
    </v-row>
</template>

<style scoped>
.v-row {
    row-gap: var(--ark-space-2);
}
</style>
