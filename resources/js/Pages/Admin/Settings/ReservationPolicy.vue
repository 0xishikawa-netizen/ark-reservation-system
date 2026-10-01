<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

interface CancellationTier {
    min_hours_before: number;
    refund_percent: number;
}

interface EditableCancellationTier {
    min_hours_before: number | null;
    refund_percent: number | null;
}

interface ReservationPolicy {
    tiers: CancellationTier[];
    no_show_refund_percent: number;
}

const props = withDefaults(
    defineProps<{
        policy: ReservationPolicy;
        errors?: Record<string, string | undefined>;
    }>(),
    {
        errors: () => ({}),
    },
);

const tiers = ref<EditableCancellationTier[]>(
    props.policy.tiers.map((tier) => ({ ...tier })),
);
const noShowRefundPercent = ref(props.policy.no_show_refund_percent);
const processing = ref(false);

const addTier = (): void => {
    tiers.value.push({
        min_hours_before: null,
        refund_percent: null,
    });
};

const removeTier = (index: number): void => {
    tiers.value.splice(index, 1);
};

const updatePolicy = (): void => {
    router.patch(
        '/admin/settings/reservation',
        {
            tiers: tiers.value,
            no_show_refund_percent: noShowRefundPercent.value,
        },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="予約ポリシー" />

    <div class="ark-settings-page">
    <PageHeader
        title="予約ポリシー"
        subtitle="予約開始までの時間に応じたカード決済の自動返金率を設定します。"
    />

    <SectionCard title="キャンセル時の返金率">
        <v-alert type="info" variant="tonal" class="mb-5">
            {{ MESSAGES.settings.cancellationTierHint }}
        </v-alert>

        <v-table class="policy-table">
            <thead>
                <tr>
                    <th scope="col">予約開始まで</th>
                    <th scope="col">返金率</th>
                    <th scope="col" class="action-column">
                        <span class="sr-only">操作</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(tier, index) in tiers" :key="index">
                    <td>
                        <v-text-field
                            v-model.number="tier.min_hours_before"
                            type="number"
                            min="0"
                            suffix="時間以上"
                            hide-details="auto"
                            :error-messages="errors[`tiers.${index}.min_hours_before`]"
                            :aria-label="`段階${index + 1}の予約開始までの時間`"
                        />
                    </td>
                    <td>
                        <v-text-field
                            v-model.number="tier.refund_percent"
                            type="number"
                            min="0"
                            max="100"
                            suffix="%"
                            hide-details="auto"
                            :error-messages="errors[`tiers.${index}.refund_percent`]"
                            :aria-label="`段階${index + 1}の返金率`"
                        />
                    </td>
                    <td class="action-column">
                        <v-btn
                            icon="mdi-delete-outline"
                            variant="text"
                            color="error"
                            :disabled="tiers.length === 1 || processing"
                            :aria-label="`段階${index + 1}を削除`"
                            @click="removeTier(index)"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>

        <v-alert v-if="errors.tiers" type="error" variant="tonal" class="mt-3">
            {{ errors.tiers }}
        </v-alert>

        <v-btn
            prepend-icon="mdi-plus"
            variant="tonal"
            class="mt-4"
            :disabled="processing"
            @click="addTier"
        >
            段階を追加
        </v-btn>

        <v-divider class="my-6" />

        <v-text-field
            v-model.number="noShowRefundPercent"
            type="number"
            min="0"
            max="100"
            suffix="%"
            label="無断キャンセル時の返金率"
            max-width="360"
            :error-messages="errors.no_show_refund_percent"
        />

        <div class="d-flex justify-end mt-4">
            <v-btn color="primary" :loading="processing" @click="updatePolicy">
                保存
            </v-btn>
        </div>
    </SectionCard>
    </div>
</template>

<style scoped>
.ark-settings-page {
    max-width: 960px;
    margin-inline: auto;
}

.policy-table th,
.policy-table td {
    padding: 12px;
    vertical-align: top;
}

.policy-table th:first-child,
.policy-table th:nth-child(2) {
    width: 42%;
}

.action-column {
    width: 64px;
    text-align: center;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
