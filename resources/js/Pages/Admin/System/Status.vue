<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

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

interface SystemStatusProps {
    stripe_mode: StripeMode;
    reservation_authority: string;
    external_gateway: string;
    failed_jobs: FailedJobsStatus;
    stale_pending_reservations: number;
    db_size: DbSizeStatus;
    reconcile: UnmeasuredStatus;
    last_backup: UnmeasuredStatus;
    real_stripe_test_mode_qa: 'incomplete';
    membership_production_readiness: 'not_ready';
}

const props = defineProps<SystemStatusProps>();

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
        placeholder: 'プレースホルダー',
        missing: '未設定',
    };

    return labels[props.stripe_mode];
});

const qaLabel = computed<string>(() =>
    props.real_stripe_test_mode_qa === 'incomplete' ? '未完了' : '完了',
);

const membershipReadinessLabel = computed<string>(() =>
    props.membership_production_readiness === 'not_ready' ? '不可' : '可',
);
</script>

<template>
    <Head title="システム状態" />

    <h1 class="text-h4 mb-6">システム状態</h1>

    <v-alert
        type="warning"
        color="red-darken-1"
        variant="tonal"
        prominent
        class="mb-6"
    >
        実 Stripe Test Mode 結合 QA: {{ qaLabel }} ／ 会員機能の本番投入:
        {{ membershipReadinessLabel }}
    </v-alert>

    <v-row>
        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title class="d-flex align-center justify-space-between ga-3">
                    Stripe モード
                    <v-chip :color="stripeChipColor" variant="tonal">
                        {{ stripeLabel }}
                    </v-chip>
                </v-card-title>
                <v-card-text v-if="stripe_mode === 'live'" class="text-error font-weight-bold">
                    ローカル/検証で Live は使用禁止
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title>予約・外部連携</v-card-title>
                <v-card-text>
                    <div class="mb-3">
                        <div class="text-caption text-medium-emphasis">予約権限 (authority)</div>
                        <div>{{ reservation_authority }}</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">外部ゲートウェイ</div>
                        <div>{{ external_gateway }}</div>
                    </div>
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title class="d-flex align-center justify-space-between ga-3">
                    失敗ジョブ
                    <v-chip :color="failed_jobs.count === 0 ? 'green' : 'red'" variant="tonal">
                        {{ failed_jobs.count === 0 ? 'OK' : '要確認' }}
                    </v-chip>
                </v-card-title>
                <v-card-text>
                    <div class="text-h5 mb-2">{{ failed_jobs.count }} 件</div>
                    <div class="text-body-2 text-medium-emphasis">
                        最古の失敗日時: {{ failed_jobs.oldest_failed_at ?? 'なし' }}
                    </div>
                    <v-btn
                        href="/admin/system/failed-jobs"
                        variant="text"
                        color="primary"
                        class="mt-3 px-0"
                    >
                        失敗ジョブを確認
                    </v-btn>
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title class="d-flex align-center justify-space-between ga-3">
                    仮予約 滞留
                    <v-chip
                        :color="stale_pending_reservations === 0 ? 'green' : 'amber'"
                        variant="tonal"
                    >
                        {{ stale_pending_reservations === 0 ? 'OK' : '注意' }}
                    </v-chip>
                </v-card-title>
                <v-card-text class="text-h5">
                    {{ stale_pending_reservations }} 件
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title class="d-flex align-center justify-space-between ga-3">
                    DB 使用量
                    <v-chip
                        :color="db_size.over_threshold ? 'red' : db_size.latest_mb === null ? 'grey' : 'green'"
                        variant="tonal"
                    >
                        {{ db_size.over_threshold ? '閾値超過' : db_size.latest_mb === null ? '未計測' : 'OK' }}
                    </v-chip>
                </v-card-title>
                <v-card-text>
                    <div class="text-h6">
                        <template v-if="db_size.latest_mb === null">未計測</template>
                        <template v-else>{{ db_size.latest_mb }} MB</template>
                        <span class="text-body-1 text-medium-emphasis">
                            / 閾値 {{ db_size.alert_mb }} MB
                        </span>
                    </div>
                    <div v-if="db_size.captured_on" class="text-body-2 text-medium-emphasis mt-2">
                        計測日: {{ db_size.captured_on }}
                    </div>
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title class="d-flex align-center justify-space-between ga-3">
                    同期突合
                    <v-chip color="grey" variant="tonal">未計測</v-chip>
                </v-card-title>
                <v-card-text>{{ reconcile.note }}</v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card variant="outlined" height="100%">
                <v-card-title class="d-flex align-center justify-space-between ga-3">
                    最終バックアップ
                    <v-chip color="grey" variant="tonal">未計測</v-chip>
                </v-card-title>
                <v-card-text>{{ last_backup.note }}</v-card-text>
            </v-card>
        </v-col>
    </v-row>
</template>
