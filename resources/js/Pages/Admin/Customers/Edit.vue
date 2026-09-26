<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import CustomerPicker, { type PickedCustomer } from '@/components/checkout/CustomerPicker.vue';
import { ref } from 'vue';

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

    <v-card max-width="760" title="顧客プロフィール編集">
        <v-card-text>
            <v-alert type="info" variant="tonal" class="mb-5">
                {{ MESSAGES.customer.emailNotEditable }}
            </v-alert>

            <v-text-field
                :model-value="customer.email"
                label="メールアドレス"
                readonly
            />

            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="氏名"
                    maxlength="255"
                    :error-messages="form.errors.name"
                />
                <v-text-field
                    v-model="form.kana"
                    label="カナ"
                    maxlength="100"
                    :error-messages="form.errors.kana"
                    required
                />
                <v-text-field
                    v-model="form.phone"
                    label="電話番号"
                    type="tel"
                    maxlength="20"
                    :error-messages="form.errors.phone"
                />
                <v-text-field
                    v-model="form.birthday"
                    label="生年月日"
                    type="date"
                    :error-messages="form.errors.birthday"
                />
                <v-select
                    v-model="form.gender"
                    label="性別"
                    :items="genderOptions"
                    clearable
                    :error-messages="form.errors.gender"
                />
                <v-textarea
                    v-model="form.note"
                    label="メモ"
                    maxlength="1000"
                    counter
                    :error-messages="form.errors.note"
                />

                <div class="d-flex ga-3 flex-wrap">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        保存
                    </v-btn>
                    <v-btn
                        variant="text"
                        :href="`/admin/customers/${customer.user_id}`"
                    >
                        キャンセル
                    </v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>

    <v-card v-if="karte" max-width="760" :title="karteLabels.karteTitle" :subtitle="karteLabels.karteSubtitle" class="mt-4" data-testid="karte-card">
        <v-card-text>
            <v-form @submit.prevent="saveKarte">
                <v-select v-model="karteForm.acquisition_channel_id" :items="acquisitionChannels" item-title="name" item-value="id" :label="karteLabels.acquisitionChannel"
                    clearable :readonly="!canManageKarte" :error-messages="karteForm.errors.acquisition_channel_id" />
                <v-text-field v-model="karteForm.acquisition_note" :label="karteLabels.acquisitionNote" maxlength="100" :readonly="!canManageKarte" :error-messages="karteForm.errors.acquisition_note" />
                <v-select v-model="karteForm.visit_purpose_ids" :items="visitPurposes" item-title="name" item-value="id" :label="karteLabels.visitPurposes"
                    multiple chips closable-chips :readonly="!canManageKarte" :error-messages="karteForm.errors.visit_purpose_ids" />
                <v-text-field v-model="karteForm.visit_purpose_note" :label="karteLabels.visitPurposeNote" maxlength="255" :readonly="!canManageKarte" :error-messages="karteForm.errors.visit_purpose_note" />
                <CustomerPicker v-if="canManageKarte" v-model="referrer" :endpoint="customerSearchEndpoint" :label="karteLabels.referrerCustomer" class="mb-4" />
                <v-text-field v-model="karteForm.referrer_name" :label="karteLabels.referrerName" maxlength="100" :readonly="!canManageKarte" :error-messages="karteForm.errors.referrer_name" />
                <div class="d-flex ga-3 flex-wrap">
                    <v-select v-model="karteForm.prefecture" :items="prefectures" :label="karteLabels.prefecture" clearable :readonly="!canManageKarte"
                        style="max-width: 220px" :error-messages="karteForm.errors.prefecture" />
                    <v-text-field v-model="karteForm.city" :label="karteLabels.city" :hint="karteLabels.cityHint" persistent-hint maxlength="50"
                        :readonly="!canManageKarte" :error-messages="karteForm.errors.city" />
                </div>
                <v-btn v-if="canManageKarte" type="submit" color="primary" class="mt-4" :loading="karteForm.processing" data-testid="save-karte">{{ karteLabels.karteSave }}</v-btn>
            </v-form>
        </v-card-text>
    </v-card>
</template>
