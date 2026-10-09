<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: GuestBookingLayout });

/** SMS 認証コードの桁数。 */
const OTP_CODE_LENGTH = 6;

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
    <Head :title="MESSAGES.customerUi.bookingFind.title" />

    <PageHeader
        :title="MESSAGES.customerUi.bookingFind.title"
        :subtitle="MESSAGES.customerUi.bookingFind.subtitle"
    />

    <SectionCard>
        <v-form @submit.prevent="codeSent ? verify() : sendCode()">
            <v-text-field
                v-model="form.phone"
                :label="MESSAGES.customerUi.bookingFind.phone"
                autocomplete="tel"
                persistent-hint
                :hint="MESSAGES.customerUi.bookingFind.phoneHint"
                :error-messages="form.errors.phone"
                required
            />

            <v-expand-transition>
                <div v-if="codeSent">
                    <v-text-field
                        v-model="form.code"
                        :label="MESSAGES.customerUi.bookingFind.code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :maxlength="OTP_CODE_LENGTH"
                        :error-messages="form.errors.code"
                        required
                    />
                    <v-btn
                        type="submit"
                        color="primary"
                        size="large"
                        block
                        :loading="form.processing"
                        :disabled="form.phone.trim() === '' || form.code.length !== OTP_CODE_LENGTH"
                    >
                        {{ MESSAGES.customerUi.bookingFind.show }}
                    </v-btn>
                    <v-btn
                        variant="text"
                        block
                        class="mt-2"
                        :disabled="form.processing"
                        @click="sendCode"
                    >
                        {{ MESSAGES.customerUi.bookingFind.resend }}
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
                    {{ MESSAGES.customerUi.bookingFind.send }}
                </v-btn>
            </v-expand-transition>
        </v-form>
    </SectionCard>

    <p class="text-center text-body-2 mt-4 mb-0">
        <a href="/booking">{{ MESSAGES.customerUi.bookingFind.backToBooking }}</a>
    </p>
</template>
