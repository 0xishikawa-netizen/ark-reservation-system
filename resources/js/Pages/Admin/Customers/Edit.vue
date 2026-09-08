<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

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

const props = defineProps<{ customer: CustomerProfile }>();

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
                メールアドレスはこの画面では変更できません。
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
</template>
