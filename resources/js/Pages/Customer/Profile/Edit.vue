<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: CustomerLayout });

interface CustomerProfile {
    user_id: number;
    name: string;
    kana: string;
    phone: string | null;
    birthday: string | null;
    gender: string | null;
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
});

const submit = (): void => {
    form.put('/mypage/profile');
};
</script>

<template>
    <Head title="プロフィール編集" />

    <v-card title="プロフィール編集">
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
                    autocomplete="name"
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
                    autocomplete="tel"
                    :error-messages="form.errors.phone"
                />
                <v-text-field
                    v-model="form.birthday"
                    label="生年月日"
                    type="date"
                    autocomplete="bday"
                    :error-messages="form.errors.birthday"
                />
                <v-select
                    v-model="form.gender"
                    label="性別"
                    :items="genderOptions"
                    clearable
                    :error-messages="form.errors.gender"
                />

                <div class="d-flex ga-3 flex-wrap">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        保存
                    </v-btn>
                    <v-btn variant="text" href="/mypage/profile">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
