<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';

defineOptions({ layout: GuestBookingLayout });

const props = defineProps<{
    code_sent: boolean;
}>();

const codeSent = ref(props.code_sent);
const form = useForm({
    phone: '',
    code: '',
});

const sendCode = (): void => {
    form.post('/booking/find/send-code', {
        preserveScroll: true,
        onSuccess: () => {
            codeSent.value = true;
            form.clearErrors();
        },
    });
};

const verify = (): void => {
    form.post('/booking/find/verify', { preserveScroll: true });
};
</script>

<template>
    <Head title="予約を探す" />

    <PageHeader
        title="予約を探す"
        subtitle="予約時の電話番号へ認証コードをお送りします。"
    />

    <SectionCard>
        <v-form @submit.prevent="codeSent ? verify() : sendCode()">
            <v-text-field
                v-model="form.phone"
                label="電話番号"
                autocomplete="tel"
                persistent-hint
                hint="予約時に入力した電話番号"
                :error-messages="form.errors.phone"
                required
            />

            <v-expand-transition>
                <div v-if="codeSent">
                    <v-text-field
                        v-model="form.code"
                        label="認証コード"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        :error-messages="form.errors.code"
                        required
                    />
                    <v-btn
                        type="submit"
                        color="primary"
                        size="large"
                        block
                        :loading="form.processing"
                        :disabled="form.phone.trim() === '' || form.code.length !== 6"
                    >
                        予約を表示する
                    </v-btn>
                    <v-btn
                        variant="text"
                        block
                        class="mt-2"
                        :disabled="form.processing"
                        @click="sendCode"
                    >
                        認証コードを再送する
                    </v-btn>
                </div>
                <v-btn
                    v-else
                    type="submit"
                    color="primary"
                    size="large"
                    block
                    :loading="form.processing"
                    :disabled="form.phone.trim() === ''"
                >
                    認証コードを送る
                </v-btn>
            </v-expand-transition>
        </v-form>
    </SectionCard>

    <p class="text-center text-body-2 mt-4 mb-0">
        <a href="/booking">予約画面へ戻る</a>
    </p>
</template>
