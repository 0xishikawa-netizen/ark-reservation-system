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
    { title: MESSAGES.customerUi.profile.genders.male, value: 'male' },
    { title: MESSAGES.customerUi.profile.genders.female, value: 'female' },
    { title: MESSAGES.customerUi.profile.genders.other, value: 'other' },
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
    <Head :title="MESSAGES.customerUi.profile.editTitle" />

    <v-card :title="MESSAGES.customerUi.profile.editTitle">
        <v-card-text>
            <v-alert type="info" variant="tonal" class="mb-5">
                {{ MESSAGES.customer.emailNotEditable }}
            </v-alert>

            <v-text-field
                :model-value="customer.email"
                :label="MESSAGES.customerUi.profile.email"
                readonly
            />

            <v-form @submit.prevent="submit">
                <v-text-field class="ark-field-name"
                    v-model="form.name"
                    :label="MESSAGES.customerUi.profile.name"
                    maxlength="255"
                    autocomplete="name"
                    :error-messages="form.errors.name"
                />
                <v-text-field class="ark-field-name"
                    v-model="form.kana"
                    :label="MESSAGES.customerUi.profile.kana"
                    maxlength="100"
                    :error-messages="form.errors.kana"
                    required
                />
                <v-text-field
                    v-model="form.phone"
                    :label="MESSAGES.customerUi.profile.phone"
                    type="tel"
                    maxlength="20"
                    autocomplete="tel"
                    :error-messages="form.errors.phone"
                />
                <!-- 生年月日は何十年も前を選ぶため、カレンダーではなく日付の直接入力のまま（幅だけ他の日付と同じ）。 -->
                <v-text-field
                    v-model="form.birthday"
                    class="ark-field-date"
                    :label="MESSAGES.customerUi.profile.birthday"
                    type="date"
                    autocomplete="bday"
                    :error-messages="form.errors.birthday"
                />
                <v-select
                    v-model="form.gender"
                    :label="MESSAGES.customerUi.profile.gender"
                    :items="genderOptions"
                    clearable
                    :error-messages="form.errors.gender"
                />

                <div class="d-flex ga-3 flex-wrap">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        {{ MESSAGES.customerUi.profile.save }}
                    </v-btn>
                    <v-btn variant="text" href="/mypage/profile">{{ MESSAGES.customerUi.profile.cancel }}</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
