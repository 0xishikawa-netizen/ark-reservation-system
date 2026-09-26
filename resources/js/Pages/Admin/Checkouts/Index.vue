<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { DateField, EmptyValue, PageHeader, SectionCard } from '@/components/ark';
import CustomerPicker, { type PickedCustomer } from '@/components/checkout/CustomerPicker.vue';
import { ReportFilterBar, ReportFilterField, ReportTable, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Row {
    kind: 'visit' | 'sale';
    id: number;
    customer_name: string | null;
    primary_staff_name: string | null;
    visit_status: string | null;
    has_reservation: boolean;
    checkout_status: string | null;
    total_amount: number | null;
    url: string;
}

const props = defineProps<{ date: string; rows: Row[]; customerSearchEndpoint: string }>();
const labels = MESSAGES.checkout;
const date = ref(props.date);
const dialog = ref<'walk_in' | 'sale' | null>(null);
const customer = ref<PickedCustomer | null>(null);
const busy = ref(false);

const statusLabel = (status: string | null): string => {
    switch (status) {
        case 'draft': return labels.statusDraft;
        case 'completed': return labels.statusCompleted;
        case 'finalized': return labels.statusFinalized;
        case 'voided': return labels.statusVoided;
        default: return labels.statusNone;
    }
};

function changeDate(value: string): void {
    if (!value || value === date.value) return;
    date.value = value;
    router.get('/admin/checkouts', { date: value }, { preserveState: false });
}

function openDialog(kind: 'walk_in' | 'sale'): void {
    customer.value = null;
    dialog.value = kind;
}

function create(): void {
    if (dialog.value === null) return;
    busy.value = true;
    const onFinish = () => { busy.value = false; };
    if (dialog.value === 'walk_in') {
        if (!customer.value) { busy.value = false; return; }
        router.post('/admin/visits', { customer_id: customer.value.user_id, business_date: date.value }, { onFinish });
    } else {
        router.post('/admin/checkouts', { customer_id: customer.value?.user_id ?? null, sale_date: date.value }, { onFinish });
    }
}
</script>

<template>
    <Head :title="labels.indexTitle" />
    <PageHeader :title="labels.indexTitle" :subtitle="labels.indexSubtitle">
        <template #actions>
            <v-btn variant="outlined" color="primary" prepend-icon="mdi-account-plus-outline" data-testid="new-walk-in" @click="openDialog('walk_in')">{{ labels.newWalkIn }}</v-btn>
            <v-btn variant="flat" color="primary" prepend-icon="mdi-shopping-outline" data-testid="new-sale" @click="openDialog('sale')">{{ labels.newSale }}</v-btn>
        </template>
    </PageHeader>
    <ReportFilterBar>
        <ReportFilterField size="lg">
            <DateField :model-value="date" :label="labels.date" density="compact" :clearable="false" data-testid="checkout-date" @update:model-value="changeDate" />
        </ReportFilterField>
    </ReportFilterBar>
    <SectionCard :title="labels.indexTitle">
        <ReportTable min-width="760px" max-height="none" data-testid="checkout-rows">
            <thead>
                <tr>
                    <th>{{ labels.itemType }}</th><th>{{ labels.customer }}</th><th>{{ labels.primaryStaff }}</th>
                    <th>{{ labels.visitStatus }}</th><th>{{ labels.checkoutStatus }}</th><th class="num">{{ labels.total }}</th><th />
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="`${row.kind}-${row.id}`">
                    <td>{{ row.kind === 'visit' ? labels.kindVisit : labels.kindSale }}<small v-if="row.kind === 'visit'" class="muted">（{{ row.has_reservation ? labels.reservationLinked : labels.walkIn }}）</small></td>
                    <td><template v-if="row.customer_name">{{ row.customer_name }}</template><EmptyValue v-else :label="labels.anonymous" /></td>
                    <td><template v-if="row.primary_staff_name">{{ row.primary_staff_name }}</template><EmptyValue v-else /></td>
                    <td><template v-if="row.kind === 'visit'">{{ statusLabel(row.visit_status) }}</template><EmptyValue v-else /></td>
                    <td>{{ statusLabel(row.checkout_status) }}</td>
                    <td class="num"><ReportValue :value="row.total_amount" format="money" /></td>
                    <td><v-btn size="small" variant="text" color="primary" :href="row.url">{{ labels.open }}</v-btn></td>
                </tr>
                <tr v-if="rows.length === 0"><td colspan="7" class="empty-cell">{{ labels.noRows }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>

    <v-dialog :model-value="dialog !== null" max-width="480" @update:model-value="(v: boolean) => { if (!v) dialog = null; }">
        <v-card>
            <v-card-title>{{ dialog === 'walk_in' ? labels.newWalkIn : labels.newSale }}</v-card-title>
            <v-card-text>
                <p class="mb-3">{{ labels.date }}: {{ date }}</p>
                <CustomerPicker v-model="customer" :endpoint="customerSearchEndpoint" :label="dialog === 'sale' ? labels.customerOptional : labels.customerSearch" />
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="dialog = null">{{ labels.close }}</v-btn>
                <v-btn color="primary" variant="flat" :loading="busy" :disabled="dialog === 'walk_in' && !customer" data-testid="create-entry" @click="create">{{ labels.create }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.muted { color: #6b7785; margin-left: 4px; }
</style>
