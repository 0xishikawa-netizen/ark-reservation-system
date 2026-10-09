<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

// 返金率の入力範囲。
const MIN_REFUND_PERCENT = 0;
const MAX_REFUND_PERCENT = 100;
// 段階番号は画面上で1始まり。
const TIER_NUMBER_OFFSET = 1;
// 返金段階は最低1行を維持する。
const MINIMUM_TIER_COUNT = 1;

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
    <Head :title="MESSAGES.mastersUi.reservationPolicy.title" />

    <div class="ark-settings-page">
    <PageHeader
        :title="MESSAGES.mastersUi.reservationPolicy.title"
        :subtitle="MESSAGES.mastersUi.reservationPolicy.subtitle"
    />

    <SectionCard :title="MESSAGES.mastersUi.reservationPolicy.sectionTitle">
        <v-alert type="info" variant="tonal" class="mb-5">
            {{ MESSAGES.settings.cancellationTierHint }}
        </v-alert>

        <v-table class="policy-table">
            <thead>
                <tr>
                    <th scope="col">{{ MESSAGES.mastersUi.reservationPolicy.beforeStart }}</th>
                    <th scope="col">{{ MESSAGES.mastersUi.reservationPolicy.refundRate }}</th>
                    <th scope="col" class="action-column">
                        <span class="sr-only">{{ MESSAGES.mastersUi.reservationPolicy.operation }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(tier, index) in tiers" :key="index">
                    <td>
                        <v-text-field
                            v-model.number="tier.min_hours_before"
                            type="number"
                            :min="MIN_REFUND_PERCENT"
                            :suffix="MESSAGES.mastersUi.reservationPolicy.hoursOrMore"
                            hide-details="auto"
                            :error-messages="errors[`tiers.${index}.min_hours_before`]"
                            :aria-label="fillMessage(MESSAGES.mastersUi.reservationPolicy.tierHoursAria, { number: String(index + TIER_NUMBER_OFFSET) })"
                        />
                    </td>
                    <td>
                        <v-text-field
                            v-model.number="tier.refund_percent"
                            type="number"
                            :min="MIN_REFUND_PERCENT"
                            :max="MAX_REFUND_PERCENT"
                            suffix="%"
                            hide-details="auto"
                            :error-messages="errors[`tiers.${index}.refund_percent`]"
                            :aria-label="fillMessage(MESSAGES.mastersUi.reservationPolicy.tierRefundAria, { number: String(index + TIER_NUMBER_OFFSET) })"
                        />
                    </td>
                    <td class="action-column">
                        <v-btn
                            icon="mdi-delete-outline"
                            variant="text"
                            color="error"
                            :disabled="tiers.length === MINIMUM_TIER_COUNT || processing"
                            :aria-label="fillMessage(MESSAGES.mastersUi.reservationPolicy.tierDeleteAria, { number: String(index + TIER_NUMBER_OFFSET) })"
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
            {{ MESSAGES.mastersUi.reservationPolicy.addTier }}
        </v-btn>

        <v-divider class="my-6" />

        <v-text-field
            v-model.number="noShowRefundPercent"
            type="number"
            :min="MIN_REFUND_PERCENT"
            :max="MAX_REFUND_PERCENT"
            suffix="%"
            :label="MESSAGES.mastersUi.reservationPolicy.noShowRefund"
            max-width="360"
            :error-messages="errors.no_show_refund_percent"
        />

        <div class="d-flex justify-end mt-4">
            <v-btn color="primary" :loading="processing" @click="updatePolicy">
                {{ MESSAGES.mastersUi.reservationPolicy.save }}
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
