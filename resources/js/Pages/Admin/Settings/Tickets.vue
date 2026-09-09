<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface TicketPolicy {
    no_show_policy: string;
    expiration_hold_policy: string;
}

interface PolicyOption {
    value: string;
    label: string;
    description: string;
}

interface TicketPolicyOptions {
    no_show: PolicyOption[];
    expiration_hold: PolicyOption[];
}

interface TicketPolicyErrors {
    no_show_policy?: string;
    expiration_hold_policy?: string;
    reason?: string;
}

const props = withDefaults(
    defineProps<{
        policy: TicketPolicy;
        options: TicketPolicyOptions;
        errors?: TicketPolicyErrors;
    }>(),
    {
        errors: () => ({}),
    },
);

const noShowPolicy = ref(props.policy.no_show_policy);
const expirationHoldPolicy = ref(props.policy.expiration_hold_policy);
const reason = ref('');
const confirmationOpen = ref(false);
const processing = ref(false);

const updatePolicy = (): void => {
    router.patch(
        '/admin/settings/tickets',
        {
            no_show_policy: noShowPolicy.value,
            expiration_hold_policy: expirationHoldPolicy.value,
            reason: reason.value,
        },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onSuccess: () => {
                confirmationOpen.value = false;
                reason.value = '';
            },
            onError: () => {
                confirmationOpen.value = false;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="回数券運用設定" />

    <PageHeader
        title="回数券運用設定"
        subtitle="回数券予約の無断キャンセルと有効期限到来時の扱いを設定します。"
    />

    <SectionCard title="運用ポリシー" max-width="880">
        <div class="ark-policy-form">
            <section aria-labelledby="no-show-policy-heading">
                <h2 id="no-show-policy-heading" class="text-h6 mb-2">
                    無断キャンセル時の扱い
                </h2>
                <v-radio-group
                    v-model="noShowPolicy"
                    :error-messages="errors.no_show_policy"
                >
                    <div
                        v-for="option in options.no_show"
                        :key="option.value"
                        class="mb-3"
                    >
                        <v-radio
                            :value="option.value"
                            :label="option.label"
                            color="primary"
                        />
                        <p class="text-body-2 text-medium-emphasis ml-8">
                            {{ option.description }}
                        </p>
                    </div>
                </v-radio-group>
            </section>

            <v-divider class="my-5" />

            <section aria-labelledby="expiration-hold-policy-heading">
                <h2 id="expiration-hold-policy-heading" class="text-h6 mb-2">
                    有効期限到来時の予約確保分
                </h2>
                <v-radio-group
                    v-model="expirationHoldPolicy"
                    :error-messages="errors.expiration_hold_policy"
                >
                    <div
                        v-for="option in options.expiration_hold"
                        :key="option.value"
                        class="mb-3"
                    >
                        <v-radio
                            :value="option.value"
                            :label="option.label"
                            color="primary"
                        />
                        <p class="text-body-2 text-medium-emphasis ml-8">
                            {{ option.description }}
                        </p>
                    </div>
                </v-radio-group>
            </section>

            <v-divider class="my-5" />

            <v-textarea
                v-model="reason"
                label="変更理由"
                :error-messages="errors.reason"
                maxlength="255"
                counter="255"
                rows="3"
                required
            />

            <div class="d-flex justify-end mt-4">
                <v-btn
                    color="primary"
                    :disabled="processing"
                    @click="confirmationOpen = true"
                >
                    保存
                </v-btn>
            </div>
        </div>
    </SectionCard>

    <v-dialog v-model="confirmationOpen" max-width="600">
        <v-card title="回数券運用設定を変更しますか？">
            <v-card-text>
                この変更は今後作成される回数券利用予約に適用されます。既存の HOLD 済み予約には遡及適用されません。
            </v-card-text>
            <v-card-actions class="justify-end">
                <v-btn :disabled="processing" @click="confirmationOpen = false">
                    キャンセル
                </v-btn>
                <v-btn color="primary" :loading="processing" @click="updatePolicy">
                    変更する
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.ark-policy-form {
    padding: var(--ark-space-2);
}
</style>
