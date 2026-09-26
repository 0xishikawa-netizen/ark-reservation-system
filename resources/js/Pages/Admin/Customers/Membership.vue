<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

interface CustomerSummary {
    user_id: number;
    name: string;
}

interface MembershipDetail {
    id: number;
    plan: {
        id: number;
        name: string;
        price: number;
        usage_count_per_period: number;
        billing_interval: string;
        stripe_price_id: string;
        is_active: boolean;
    };
    status: string;
    status_label: string;
    current_period_start: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    available: number;
    held: number;
    total: number;
    grace_until: string | null;
    started_at: string | null;
    canceled_at: string | null;
    last_synced_at: string | null;
    needs_attention: boolean;
}

interface MembershipHistoryItem {
    id: number;
    membership_id: number;
    type: string;
    type_label: string;
    delta: number;
    period_start: string;
    reservation_id: number | null;
    reason: string | null;
    created_at: string;
}

const props = defineProps<{
    customer: CustomerSummary;
    membership: MembershipDetail | null;
    history: MembershipHistoryItem[];
    can: { adjust: boolean };
}>();

const adjustDialog = ref(false);
const cancelDialog = ref(false);
const operationKey = (): string => crypto.randomUUID();

const adjustForm = useForm({
    delta: 1,
    reason: '',
    operation_key: operationKey(),
});

const cancelForm = useForm({ reason: '' });
const syncForm = useForm({});
const adjustBusinessError = computed(
    () => (adjustForm.errors as Record<string, string>).membership,
);

const historyHeaders = [
    { title: '日時', key: 'created_at' },
    { title: 'Membership', key: 'membership_id' },
    { title: '種別', key: 'type_label' },
    { title: '増減', key: 'delta' },
    { title: '期', key: 'period_start' },
    { title: '予約ID', key: 'reservation_id' },
    { title: '理由', key: 'reason' },
] as const;

function formatDate(value: string | null): string {
    if (!value) return MESSAGES.common.notSet;

    return new Intl.DateTimeFormat('ja-JP', { dateStyle: 'medium' })
        .format(new Date(`${value}T00:00:00`));
}

function formatDateTime(value: string | null): string {
    if (!value) return MESSAGES.common.notRecorded;

    return new Intl.DateTimeFormat('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value.replace(' ', 'T')));
}

function formatPrice(price: number): string {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(price);
}

function signed(delta: number): string {
    return delta > 0 ? `+${delta}` : String(delta);
}

function openAdjust(): void {
    adjustForm.clearErrors();
    adjustForm.delta = 1;
    adjustForm.reason = '';
    adjustForm.operation_key = operationKey();
    adjustDialog.value = true;
}

function submitAdjust(): void {
    if (!props.membership) return;

    adjustForm.post(`/admin/memberships/${props.membership.id}/adjust`, {
        preserveScroll: true,
        onSuccess: () => { adjustDialog.value = false; },
    });
}

function submitCancel(): void {
    if (!props.membership) return;

    cancelForm.post(`/admin/memberships/${props.membership.id}/cancel-now`, {
        preserveScroll: true,
        onSuccess: () => { cancelDialog.value = false; },
    });
}

function syncMembership(): void {
    if (!props.membership) return;

    syncForm.post(`/admin/memberships/${props.membership.id}/sync`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${customer.name}の会員情報`" />

    <PageHeader title="顧客会員情報" :subtitle="customer.name">
        <template #actions>
            <v-btn variant="text" :href="`/admin/customers/${customer.user_id}`">顧客詳細へ戻る</v-btn>
        </template>
    </PageHeader>

    <EmptyState
        v-if="membership === null"
        icon="mdi-account-credit-card-outline"
        title="この顧客には利用権がありません"
        description="利用権を契約すると、こちらに契約内容と利用状況が表示されます。"
    />

    <template v-else>
        <v-alert v-if="membership.needs_attention" type="error" variant="tonal" class="mb-4">
            {{ MESSAGES.membership.stripeCheckRequired }}
        </v-alert>

        <div class="ark-page__sections">
            <SectionCard :title="membership.plan.name" class="mb-6" variant="outlined">
                <template #append>
                    <StatusChip :status="membership.status" :label="membership.status_label" />
                </template>
                <div class="d-flex ga-6 flex-wrap mb-5">
                    <div><div class="text-caption">利用可能</div><div class="text-h5 text-primary">{{ membership.available }}回</div></div>
                    <div><div class="text-caption">予約中</div><div class="text-h6">{{ membership.held }}回</div></div>
                    <div><div class="text-caption">合計</div><div class="text-h6">{{ membership.total }}回</div></div>
                </div>

                <v-row>
                    <v-col cols="12" md="6">
                        <v-list lines="two" density="compact">
                            <v-list-item title="月額" :subtitle="formatPrice(membership.plan.price)" />
                            <v-list-item title="付与回数" :subtitle="`${membership.plan.usage_count_per_period}回 / 月`" />
                            <v-list-item
                                title="当期"
                                :subtitle="`${formatDate(membership.current_period_start)} 〜 ${formatDate(membership.current_period_end)}`"
                            />
                            <v-list-item title="開始日時" :subtitle="formatDateTime(membership.started_at)" />
                            <v-list-item title="解約日時" :subtitle="formatDateTime(membership.canceled_at)" />
                        </v-list>
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-list lines="two" density="compact">
                            <v-list-item
                                title="期末解約"
                                :subtitle="membership.cancel_at_period_end ? '予約済み' : 'なし'"
                            />
                            <v-list-item title="猶予期限" :subtitle="formatDateTime(membership.grace_until)" />
                            <v-list-item title="最終同期" :subtitle="formatDateTime(membership.last_synced_at)" />
                            <v-list-item
                                title="要確認"
                                :subtitle="membership.needs_attention ? '要対応' : 'なし'"
                            />
                            <v-list-item title="Stripe 価格ID" :subtitle="membership.plan.stripe_price_id" />
                        </v-list>
                    </v-col>
                </v-row>
            <v-card-actions v-if="can.adjust" class="pa-4 pt-0 flex-wrap ga-2">
                <v-btn
                    color="primary"
                    variant="outlined"
                    :disabled="membership.status === 'canceled'"
                    @click="openAdjust"
                >
                    残数を調整（ADJUST）
                </v-btn>
                <v-btn
                    color="error"
                    variant="outlined"
                    :disabled="membership.status === 'canceled'"
                    @click="cancelDialog = true"
                >
                    即時解約
                </v-btn>
                <v-spacer />
                <v-btn color="primary" :loading="syncForm.processing" @click="syncMembership">
                    Stripe と同期
                </v-btn>
            </v-card-actions>
            </SectionCard>

            <SectionCard class="ark-table-section" title="利用台帳履歴">
            <v-data-table
                :headers="historyHeaders"
                :items="history"
                item-value="id"
                no-data-text="利用履歴はありません。"
            >
                <template #no-data>
                    <EmptyState
                        icon="mdi-history"
                        title="利用履歴はありません"
                        description="利用権の付与や予約、調整を行うと、こちらに履歴が記録されます。"
                    />
                </template>
                <template #item.created_at="{ item }">{{ formatDateTime(item.created_at) }}</template>
                <template #item.membership_id="{ item }">#{{ item.membership_id }}</template>
                <template #item.delta="{ item }">{{ signed(item.delta) }}</template>
                <template #item.period_start="{ item }">{{ formatDate(item.period_start) }}</template>
                <template #item.reservation_id="{ item }">
                    {{ item.reservation_id === null ? MESSAGES.common.notLinked : `#${item.reservation_id}` }}
                </template>
                <template #item.reason="{ item }">{{ item.reason || MESSAGES.common.notRecorded }}</template>
            </v-data-table>
            </SectionCard>
        </div>
    </template>

    <v-dialog v-model="adjustDialog" max-width="560">
        <v-card title="利用権残数を調整">
            <v-card-text>
                <v-alert type="warning" variant="tonal" class="mb-4">
                    {{ MESSAGES.common.reauthAudited }}
                </v-alert>
                <v-text-field
                    v-model.number="adjustForm.delta"
                    label="調整数（減らす場合は負数）"
                    type="number"
                    :error-messages="adjustForm.errors.delta"
                    required
                />
                <v-textarea
                    v-model="adjustForm.reason"
                    label="理由"
                    maxlength="255"
                    counter
                    :error-messages="adjustForm.errors.reason"
                    required
                />
                <v-alert v-if="adjustBusinessError" type="error" variant="tonal">
                    {{ adjustBusinessError }}
                </v-alert>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="adjustDialog = false">戻る</v-btn>
                <v-spacer />
                <v-btn color="primary" :loading="adjustForm.processing" @click="submitAdjust">調整する</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="cancelDialog" max-width="560">
        <v-card title="利用権を即時解約しますか？">
            <v-card-text>
                <v-alert type="error" variant="tonal" class="mb-4">
                    {{ MESSAGES.membership.cancelNowWarning }}
                </v-alert>
                <v-textarea
                    v-model="cancelForm.reason"
                    label="理由"
                    maxlength="255"
                    counter
                    :error-messages="cancelForm.errors.reason"
                    required
                />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelDialog = false">戻る</v-btn>
                <v-spacer />
                <v-btn color="error" :loading="cancelForm.processing" @click="submitCancel">即時解約する</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.ark-page__sections {
    display: grid;
    gap: var(--ark-space-4);
}

.ark-page__sections > * {
    margin-block: 0 !important;
}

.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
