<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { realEmail } from '@/utils/placeholderEmail';
import CustomerPicker, { type PickedCustomer } from '@/components/checkout/CustomerPicker.vue';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';

defineOptions({ layout: AdminLayout });

interface CustomerProfile {
    user_id: number;
    name: string;
    kana: string;
    phone: string | null;
    birthday: string | null;
    gender: string | null;
    note: string | null;
    email: string;
}

interface Karte {
    acquisition_channel_id: number | null; acquisition_note: string | null; visit_purpose_ids: number[]; visit_purpose_note: string | null;
    referrer_customer_id: number | null; referrer_customer_name: string | null; referrer_name: string | null;
    prefecture: string | null; city: string | null;
}
interface Option { id: number; name: string }

const props = withDefaults(defineProps<{
    customer: CustomerProfile;
    karte?: Karte | null;
    acquisitionChannels?: Option[];
    visitPurposes?: Option[];
    prefectures?: string[];
    canManageKarte?: boolean;
    customerSearchEndpoint?: string;
}>(), { karte: null, acquisitionChannels: () => [], visitPurposes: () => [], prefectures: () => [], canManageKarte: false, customerSearchEndpoint: '' });
const karteLabels = MESSAGES.customer;
const karteForm = useForm({
    acquisition_channel_id: props.karte?.acquisition_channel_id ?? null,
    acquisition_note: props.karte?.acquisition_note ?? '',
    visit_purpose_ids: [...(props.karte?.visit_purpose_ids ?? [])],
    visit_purpose_note: props.karte?.visit_purpose_note ?? '',
    referrer_customer_id: props.karte?.referrer_customer_id ?? null,
    referrer_name: props.karte?.referrer_name ?? '',
    prefecture: props.karte?.prefecture ?? null,
    city: props.karte?.city ?? '',
});
const referrer = ref<PickedCustomer | null>(props.karte?.referrer_customer_id
    ? { user_id: props.karte.referrer_customer_id, name: props.karte.referrer_customer_name ?? '', kana: null, member_no: '' }
    : null);
const saveKarte = (): void => {
    karteForm.referrer_customer_id = referrer.value?.user_id ?? null;
    karteForm.put(`/admin/customers/${props.customer.user_id}/karte`, { preserveScroll: true });
};

const genderOptions = [
    { title: '男性', value: 'male' },
    { title: '女性', value: 'female' },
    { title: 'その他', value: 'other' },
];

const form = useForm({
    name: props.customer.name,
    kana: props.customer.kana,
    phone: props.customer.phone,
    birthday: props.customer.birthday,
    gender: props.customer.gender,
    note: props.customer.note,
});

const submit = (): void => {
    form.put(`/admin/customers/${props.customer.user_id}`);
};
</script>

<template>
    <Head :title="`${customer.name}を編集`" />

    <PageHeader title="顧客プロフィール編集" :subtitle="customer.name" />

    <!-- 顧客詳細の「基本情報」と同じ並びで、値の部分だけ入力欄にする。 -->
    <SectionCard title="基本情報" class="basic-information-card">
        <v-form @submit.prevent="submit">
            <dl class="customer-profile-grid">
                <div class="customer-profile-item">
                    <dt>氏名</dt>
                    <dd><v-text-field v-model="form.name" aria-label="氏名" maxlength="255" hide-details="auto" :error-messages="form.errors.name" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>カナ</dt>
                    <dd><v-text-field v-model="form.kana" aria-label="カナ" maxlength="100" hide-details="auto" required :error-messages="form.errors.kana" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>電話番号</dt>
                    <dd><v-text-field v-model="form.phone" aria-label="電話番号" type="tel" maxlength="20" hide-details="auto" :error-messages="form.errors.phone" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>生年月日</dt>
                    <dd><v-text-field v-model="form.birthday" class="ark-field-date" aria-label="生年月日" type="date" hide-details="auto" :error-messages="form.errors.birthday" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>性別</dt>
                    <dd><v-select v-model="form.gender" aria-label="性別" :items="genderOptions" clearable hide-details="auto" :error-messages="form.errors.gender" /></dd>
                </div>
                <div class="customer-profile-item customer-profile-item--wide">
                    <dt>メールアドレス</dt>
                    <dd>
                        <v-text-field :model-value="realEmail(customer.email) ?? '-'" aria-label="メールアドレス" readonly hide-details="auto" :hint="MESSAGES.customer.emailNotEditable" persistent-hint />
                    </dd>
                </div>
                <div class="customer-profile-item customer-profile-item--wide">
                    <dt>メモ</dt>
                    <dd><v-textarea v-model="form.note" aria-label="メモ" maxlength="1000" counter rows="3" auto-grow hide-details="auto" :error-messages="form.errors.note" /></dd>
                </div>
            </dl>

            <div class="d-flex ga-3 flex-wrap mt-4">
                <v-btn type="submit" color="primary" :loading="form.processing">保存</v-btn>
                <v-btn variant="text" :href="`/admin/customers/${customer.user_id}`">キャンセル</v-btn>
            </div>
        </v-form>
    </SectionCard>

    <!-- カルテ（分析項目）も基本情報と同じ並び（ラベルの下に入力欄）にする。 -->
    <SectionCard v-if="karte" :title="karteLabels.karteTitle" :subtitle="karteLabels.karteSubtitle" class="basic-information-card" data-testid="karte-card">
        <v-form @submit.prevent="saveKarte">
            <dl class="customer-profile-grid">
                <div class="customer-profile-item">
                    <dt>{{ karteLabels.acquisitionChannel }}</dt>
                    <dd><v-select v-model="karteForm.acquisition_channel_id" :aria-label="karteLabels.acquisitionChannel" :items="acquisitionChannels" item-title="name" item-value="id"
                        clearable hide-details="auto" :readonly="!canManageKarte" :error-messages="karteForm.errors.acquisition_channel_id" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>{{ karteLabels.acquisitionNote }}</dt>
                    <dd><v-text-field v-model="karteForm.acquisition_note" :aria-label="karteLabels.acquisitionNote" maxlength="100" hide-details="auto" :readonly="!canManageKarte" :error-messages="karteForm.errors.acquisition_note" /></dd>
                </div>
                <div class="customer-profile-item customer-profile-item--wide">
                    <dt>{{ karteLabels.visitPurposes }}</dt>
                    <dd><v-select v-model="karteForm.visit_purpose_ids" :aria-label="karteLabels.visitPurposes" :items="visitPurposes" item-title="name" item-value="id"
                        multiple chips closable-chips hide-details="auto" :readonly="!canManageKarte" :error-messages="karteForm.errors.visit_purpose_ids" /></dd>
                </div>
                <div class="customer-profile-item customer-profile-item--wide">
                    <dt>{{ karteLabels.visitPurposeNote }}</dt>
                    <dd><v-text-field v-model="karteForm.visit_purpose_note" :aria-label="karteLabels.visitPurposeNote" maxlength="255" hide-details="auto" :readonly="!canManageKarte" :error-messages="karteForm.errors.visit_purpose_note" /></dd>
                </div>
                <div v-if="canManageKarte" class="customer-profile-item customer-profile-item--wide">
                    <dt>{{ karteLabels.referrerCustomer }}</dt>
                    <dd><CustomerPicker v-model="referrer" :endpoint="customerSearchEndpoint" :label="karteLabels.referrerCustomer" /></dd>
                </div>
                <div class="customer-profile-item customer-profile-item--wide">
                    <dt>{{ karteLabels.referrerName }}</dt>
                    <dd><v-text-field v-model="karteForm.referrer_name" :aria-label="karteLabels.referrerName" maxlength="100" hide-details="auto" :readonly="!canManageKarte" :error-messages="karteForm.errors.referrer_name" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>{{ karteLabels.prefecture }}</dt>
                    <dd><v-select v-model="karteForm.prefecture" :aria-label="karteLabels.prefecture" :items="prefectures" clearable hide-details="auto" :readonly="!canManageKarte" :error-messages="karteForm.errors.prefecture" /></dd>
                </div>
                <div class="customer-profile-item">
                    <dt>{{ karteLabels.city }}</dt>
                    <dd><v-text-field v-model="karteForm.city" :aria-label="karteLabels.city" :hint="karteLabels.cityHint" persistent-hint maxlength="50" :readonly="!canManageKarte" :error-messages="karteForm.errors.city" /></dd>
                </div>
            </dl>
            <div v-if="canManageKarte" class="d-flex ga-3 flex-wrap mt-4">
                <v-btn type="submit" color="primary" :loading="karteForm.processing" data-testid="save-karte">{{ karteLabels.karteSave }}</v-btn>
            </div>
        </v-form>
    </SectionCard>
</template>

<style scoped>
.basic-information-card {
    margin-bottom: var(--ark-space-5);
}

.customer-profile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin: 0;
    gap: 0 var(--ark-space-5);
}

.customer-profile-item--wide {
    grid-column: 1 / -1;
}

.customer-profile-item {
    min-width: 0;
    padding: var(--ark-space-3) 0;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.customer-profile-item dt {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    line-height: 1.4;
}

.customer-profile-item dd {
    margin: var(--ark-space-1) 0 0;
}

@media (min-width: 960px) {
    .customer-profile-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .customer-profile-item--wide {
        grid-column: span 2;
    }
}
</style>
