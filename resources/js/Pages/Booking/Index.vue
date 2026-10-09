<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { WeeklyAvailabilityTimetable } from '@/components/ark';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateTime } from '@/utils/dateFormat';
import { formatYenSuffix } from '@/utils/money';

defineOptions({ layout: GuestBookingLayout });

interface StaffOption { id: number; display_name: string }
interface ServiceOption {
    id: number;
    name: string;
    duration_min: number;
    price: number;
    color: string | null;
    staff: StaffOption[];
}
type PaymentMethod = 'single' | 'onsite';
type FieldRule = (value: string) => true | string;

/** お名前の最大文字数（サーバー側の検証と同じ）。 */
const NAME_MAX_LENGTH = 100;
/** 電話番号の最大文字数（ハイフン込み）。 */
const PHONE_MAX_LENGTH = 20;
/** 電話番号として認める数字の桁数（固定電話10桁・携帯11桁）。 */
const PHONE_DIGIT_LENGTHS = [10, 11];
/** メールアドレスの最大文字数。 */
const EMAIL_MAX_LENGTH = 190;

const props = defineProps<{ services: ServiceOption[] }>();
const stepNames = MESSAGES.customerUi.booking.steps;
const step = ref(1);
const submitErrorStep = ref<number | null>(null);
const serviceId = ref<number | null>(null);
const staffId = ref<number | null>(null);
const selectedStartsAt = ref<string | null>(null);
const source = typeof window === 'undefined'
    ? null
    : new URLSearchParams(window.location.search).get('source');

const form = useForm({
    service_id: null as number | null,
    staff_id: null as number | null,
    starts_at: null as string | null,
    name: '',
    phone: '',
    email: '',
    payment_method: 'single' as PaymentMethod,
    source,
    reservation: null as string | null,
});

const selectedService = computed(() =>
    props.services.find((service) => service.id === serviceId.value) ?? null,
);
const staffItems = computed<Array<{ title: string; value: number | null }>>(() => [
    { title: MESSAGES.customerUi.booking.staffUnspecified, value: null },
    ...(selectedService.value?.staff.map((staff) => ({
        title: staff.display_name,
        value: staff.id,
    })) ?? []),
]);
const selectedStaffName = computed(() => staffId.value === null
    ? MESSAGES.customerUi.booking.staffUnspecifiedSummary
    : selectedService.value?.staff.find((staff) => staff.id === staffId.value)?.display_name ?? MESSAGES.customerUi.booking.unselected);
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const normalizedLength = (value: string): number => Array.from(value.trim()).length;
const isJapanesePhoneNumber = (value: string): boolean => {
    const normalized = value.replace(/\D+/g, '');

    return PHONE_DIGIT_LENGTHS.includes(normalized.length) && normalized.startsWith('0');
};
const nameRules: FieldRule[] = [
    (value) => value.trim() !== '' || MESSAGES.customerUi.booking.nameRequired,
    (value) => normalizedLength(value) <= NAME_MAX_LENGTH || MESSAGES.customerUi.booking.nameTooLong,
];
const phoneRules: FieldRule[] = [
    (value) => value.trim() !== '' || MESSAGES.customerUi.booking.phoneRequired,
    (value) => Array.from(value).length <= PHONE_MAX_LENGTH || MESSAGES.customerUi.booking.phoneTooLong,
    (value) => isJapanesePhoneNumber(value)
        || MESSAGES.customerUi.booking.phoneInvalid,
];
const emailRules: FieldRule[] = [
    (value) => normalizedLength(value) <= EMAIL_MAX_LENGTH || MESSAGES.customerUi.booking.emailTooLong,
    (value) => value.trim() === '' || emailPattern.test(value.trim())
        || MESSAGES.customerUi.booking.emailInvalid,
];
const customerInfoComplete = computed(() =>
    form.name.trim() !== ''
        && normalizedLength(form.name) <= NAME_MAX_LENGTH
        && Array.from(form.phone).length <= PHONE_MAX_LENGTH
        && isJapanesePhoneNumber(form.phone)
        && normalizedLength(form.email) <= EMAIL_MAX_LENGTH
        && (form.email.trim() === '' || emailPattern.test(form.email.trim())),
);
const canSubmit = computed(() =>
    serviceId.value !== null
        && selectedStartsAt.value !== null
        && customerInfoComplete.value
        && ['single', 'onsite'].includes(form.payment_method),
);
const currentStepName = computed(() => stepNames[step.value - 1]);
const progress = computed(() => (step.value / stepNames.length) * 100);
const canAdvance = computed(() => {
    switch (step.value) {
        case 1:
            return serviceId.value !== null;
        case 2:
            return selectedService.value !== null;
        case 3:
            return selectedStartsAt.value !== null;
        case 4:
            return customerInfoComplete.value;
        case 5:
            return ['single', 'onsite'].includes(form.payment_method);
        default:
            return canSubmit.value;
    }
});

watch(serviceId, () => {
    staffId.value = null;
    selectedStartsAt.value = null;
});
watch(staffId, () => {
    selectedStartsAt.value = null;
});

function durationPriceLabel(durationMin: number, price: number): string {
    return fillMessage(MESSAGES.customerUi.booking.durationPrice, { min: String(durationMin), price: formatYenSuffix(price) });
}
function nextStep(): void {
    if (canAdvance.value && step.value < stepNames.length) step.value += 1;
}
function previousStep(): void {
    if (step.value > 1) step.value -= 1;
}
function submit(): void {
    if (!canSubmit.value || serviceId.value === null || selectedStartsAt.value === null) return;
    submitErrorStep.value = null;
    form.service_id = serviceId.value;
    form.staff_id = staffId.value;
    form.starts_at = selectedStartsAt.value;
    form.post('/booking', {
        errorBag: 'reservation',
        onError: (errors) => {
            const fieldSteps: Record<string, number> = {
                service_id: 1,
                staff_id: 2,
                starts_at: 3,
                name: 4,
                phone: 4,
                email: 4,
                payment_method: 5,
            };
            const errorSteps = Object.keys(errors)
                .map((field) => fieldSteps[field])
                .filter((errorStep): errorStep is number => errorStep !== undefined);

            if (errorSteps.length > 0) {
                submitErrorStep.value = Math.min(...errorSteps);
                step.value = submitErrorStep.value;
            }
        },
    });
}
</script>

<template>
    <Head :title="MESSAGES.customerUi.booking.title" />
    <v-card class="booking-card mx-auto" max-width="720">
        <div class="booking-card__header">
            <v-card-title class="text-h5 pa-0">{{ MESSAGES.customerUi.booking.title }}</v-card-title>
            <v-card-subtitle class="pa-0 mt-1">{{ MESSAGES.customerUi.booking.subtitle }}</v-card-subtitle>
            <p class="booking-links text-body-2 mb-0">
                {{ MESSAGES.customerUi.booking.memberPrefix }} <a href="/reserve">{{ MESSAGES.customerUi.booking.memberLogin }}</a>
                <span aria-hidden="true">・</span><a href="/booking/find">{{ MESSAGES.customerUi.booking.findReservation }}</a>
            </p>
        </div>

        <nav v-if="services.length > 0" class="booking-progress" :aria-label="MESSAGES.customerUi.booking.stepsLabel">
            <ol class="booking-stepper">
                <li
                    v-for="(stepName, index) in stepNames"
                    :key="stepName"
                    class="booking-stepper__item"
                    :class="{ 'is-current': step === index + 1, 'is-complete': step > index + 1 }"
                    :aria-current="step === index + 1 ? 'step' : undefined"
                >
                    <span class="booking-stepper__marker" aria-hidden="true">
                        <span v-if="step > index + 1">✓</span>
                        <span v-else>{{ index + 1 }}</span>
                    </span>
                    <span class="booking-stepper__label">{{ stepName }}</span>
                </li>
            </ol>
            <div class="booking-progress__mobile" aria-live="polite">
                <div class="booking-progress__mobile-label">
                    <span>{{ fillMessage(MESSAGES.customerUi.booking.stepCounter, { current: String(step), total: String(stepNames.length) }) }}</span>
                    <strong>{{ currentStepName }}</strong>
                </div>
                <v-progress-linear :model-value="progress" color="primary" height="4" rounded />
            </div>
        </nav>

        <v-card-text class="booking-card__body">
            <v-alert v-if="services.length === 0" type="info" variant="tonal">
                {{ MESSAGES.menu.noneOnlineMenu }}
            </v-alert>
            <template v-else>
                <v-alert
                    v-if="submitErrorStep === step"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >{{ MESSAGES.common.checkInputPolite }}</v-alert>
                <section v-if="step === 1" class="booking-section" aria-labelledby="service-step">
                    <h2 id="service-step" class="booking-section__title text-subtitle-1">{{ MESSAGES.customerUi.booking.selectService }}</h2>
                    <div class="service-grid">
                        <button
                            v-for="service in services" :key="service.id" type="button"
                            class="service-card" :class="{ 'is-selected': serviceId === service.id }"
                            :aria-pressed="serviceId === service.id" @click="serviceId = service.id"
                        >
                            <span class="service-card__image" aria-hidden="true">
                                <v-icon icon="mdi-image-outline" size="32" />
                            </span>
                            <span class="service-card__content">
                                <strong>{{ service.name }}</strong>
                                <span>{{ durationPriceLabel(service.duration_min, service.price) }}</span>
                            </span>
                            <v-icon v-if="serviceId === service.id" icon="mdi-check-circle" color="primary" size="24" />
                        </button>
                    </div>
                    <div v-if="form.errors.service_id" class="text-error text-body-2 mt-2">{{ form.errors.service_id }}</div>
                    <div class="booking-actions">
                        <v-btn color="primary" size="large" class="booking-primary-action" :disabled="!canAdvance" @click="nextStep">{{ MESSAGES.customerUi.booking.next }}</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 2" class="booking-section" aria-labelledby="staff-step">
                    <h2 id="staff-step" class="booking-section__title text-subtitle-1">{{ MESSAGES.customerUi.booking.selectStaff }}</h2>
                    <v-select
                        v-model="staffId" :items="staffItems" :label="MESSAGES.customerUi.booking.staffLabel"
                        persistent-hint :hint="MESSAGES.customerUi.booking.staffHint"
                        :error-messages="form.errors.staff_id"
                    />
                    <div class="booking-actions">
                        <v-btn class="booking-back-action" variant="text" @click="previousStep">{{ MESSAGES.customerUi.booking.back }}</v-btn>
                        <v-btn color="primary" size="large" class="booking-primary-action" :disabled="!canAdvance" @click="nextStep">{{ MESSAGES.customerUi.booking.next }}</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 3" class="booking-section" aria-labelledby="datetime-step">
                    <h2 id="datetime-step" class="booking-section__title text-subtitle-1">{{ MESSAGES.customerUi.booking.selectDateTime }}</h2>
                    <WeeklyAvailabilityTimetable
                        v-if="selectedService"
                        v-model="selectedStartsAt" :service-id="selectedService.id" :staff-id="staffId"
                        week-endpoint="/booking/availability/week"
                    />
                    <div v-if="form.errors.starts_at" class="text-error text-body-2 mt-2">{{ form.errors.starts_at }}</div>
                    <div class="booking-actions">
                        <v-btn class="booking-back-action" variant="text" @click="previousStep">{{ MESSAGES.customerUi.booking.back }}</v-btn>
                        <v-btn color="primary" size="large" class="booking-primary-action" :disabled="!canAdvance" @click="nextStep">{{ MESSAGES.customerUi.booking.next }}</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 4" class="booking-section" aria-labelledby="customer-step">
                    <h2 id="customer-step" class="booking-section__title text-subtitle-1">{{ MESSAGES.customerUi.booking.enterCustomer }}</h2>
                    <v-text-field
                        v-model="form.name" :label="MESSAGES.customerUi.booking.name" autocomplete="name" :rules="nameRules"
                        validate-on="input" :error-messages="form.errors.name" required
                    />
                    <v-text-field
                        v-model="form.phone" :label="MESSAGES.customerUi.booking.phone" autocomplete="tel"
                        :hint="MESSAGES.customerUi.booking.phoneHint"
                        :rules="phoneRules" validate-on="input" :error-messages="form.errors.phone" required
                    />
                    <v-text-field
                        v-model="form.email" :label="MESSAGES.customerUi.booking.emailOptional" type="email" autocomplete="email"
                        :hint="MESSAGES.customerUi.booking.emailHint"
                        :rules="emailRules" validate-on="input" :error-messages="form.errors.email"
                    />
                    <div class="booking-actions">
                        <v-btn class="booking-back-action" variant="text" @click="previousStep">{{ MESSAGES.customerUi.booking.back }}</v-btn>
                        <v-btn color="primary" size="large" class="booking-primary-action" :disabled="!canAdvance" @click="nextStep">{{ MESSAGES.customerUi.booking.next }}</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 5" class="booking-section" aria-labelledby="payment-step">
                    <h2 id="payment-step" class="booking-section__title text-subtitle-1">{{ MESSAGES.customerUi.booking.selectPayment }}</h2>
                    <v-radio-group v-model="form.payment_method" :error-messages="form.errors.payment_method">
                        <v-radio value="single" class="payment-option">
                            <template #label><div><strong>{{ MESSAGES.customerUi.booking.paymentOnline }}</strong><div class="text-body-2 text-medium-emphasis">{{ MESSAGES.customerUi.booking.paymentOnlineHint }}</div></div></template>
                        </v-radio>
                        <v-radio value="onsite" class="payment-option">
                            <template #label><div><strong>{{ MESSAGES.customerUi.booking.paymentOnsite }}</strong><div class="text-body-2 text-medium-emphasis">{{ MESSAGES.payment.payAtStore }}</div></div></template>
                        </v-radio>
                    </v-radio-group>
                    <div class="booking-actions">
                        <v-btn class="booking-back-action" variant="text" @click="previousStep">{{ MESSAGES.customerUi.booking.back }}</v-btn>
                        <v-btn color="primary" size="large" class="booking-primary-action" :disabled="!canAdvance" @click="nextStep">{{ MESSAGES.customerUi.booking.next }}</v-btn>
                    </div>
                </section>

                <section v-else class="booking-section" aria-labelledby="confirm-step">
                    <h2 id="confirm-step" class="booking-section__title text-subtitle-1">{{ MESSAGES.customerUi.booking.confirmHeading }}</h2>
                    <v-card class="reservation-summary" variant="flat">
                        <v-list lines="two" bg-color="transparent">
                            <v-list-item :title="MESSAGES.customerUi.booking.summaryService" :subtitle="selectedService?.name" />
                            <v-list-item :title="MESSAGES.customerUi.booking.summaryDurationPrice" :subtitle="durationPriceLabel(selectedService?.duration_min ?? 0, selectedService?.price ?? 0)" />
                            <v-list-item :title="MESSAGES.customerUi.booking.summaryStaff" :subtitle="selectedStaffName" />
                            <v-list-item :title="MESSAGES.customerUi.booking.summaryDateTime" :subtitle="selectedStartsAt ? formatDateTime(selectedStartsAt, 'long') : ''" />
                            <v-list-item :title="MESSAGES.customerUi.booking.name" :subtitle="form.name" />
                            <v-list-item :title="MESSAGES.customerUi.booking.phone" :subtitle="form.phone" />
                            <v-list-item :title="MESSAGES.customerUi.booking.summaryEmail" :subtitle="form.email || MESSAGES.common.notEntered" />
                            <v-list-item :title="MESSAGES.customerUi.booking.summaryPayment" :subtitle="form.payment_method === 'single' ? MESSAGES.customerUi.booking.paymentOnline : MESSAGES.customerUi.booking.paymentOnsite" />
                        </v-list>
                    </v-card>
                    <v-alert v-if="form.errors.reservation" type="error" variant="tonal" class="mb-4">{{ form.errors.reservation }}</v-alert>
                    <v-alert v-if="form.payment_method === 'single'" type="info" variant="tonal" class="mb-4">
                        {{ MESSAGES.payment.holdThenCard }}
                    </v-alert>
                    <div class="booking-actions">
                        <v-btn class="booking-back-action" variant="text" :disabled="form.processing" @click="previousStep">{{ MESSAGES.customerUi.booking.back }}</v-btn>
                        <v-btn color="primary" size="large" class="booking-primary-action" :loading="form.processing" :disabled="!canSubmit" @click="submit">{{ MESSAGES.customerUi.booking.submit }}</v-btn>
                    </div>
                </section>
            </template>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.booking-card__header { padding: var(--ark-space-5) var(--ark-space-5) var(--ark-space-4); }
.booking-links { display: flex; flex-wrap: wrap; gap: var(--ark-space-2); margin-top: var(--ark-space-4); }
.booking-progress { padding: 0 var(--ark-space-5) var(--ark-space-5); border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.booking-stepper { display: flex; padding: 0; margin: 0; list-style: none; }
.booking-stepper__item { position: relative; display: flex; flex: 1 1 0; flex-direction: column; align-items: center; gap: var(--ark-space-2); min-width: 0; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: .75rem; line-height: 1.4; text-align: center; }
.booking-stepper__item:not(:last-child)::after { position: absolute; z-index: 0; top: 15px; left: calc(50% + 18px); width: calc(100% - 36px); height: 2px; background: rgba(var(--v-border-color), var(--v-border-opacity)); content: ''; }
.booking-stepper__item.is-complete:not(:last-child)::after { background: rgb(var(--v-theme-primary)); }
.booking-stepper__marker { position: relative; z-index: 1; display: grid; width: 32px; height: 32px; place-items: center; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 50%; background: rgb(var(--v-theme-surface-light)); font-weight: 700; }
.booking-stepper__item.is-complete { color: rgb(var(--v-theme-primary)); }
.booking-stepper__item.is-complete .booking-stepper__marker { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .12); }
.booking-stepper__item.is-current { color: rgb(var(--v-theme-on-surface)); font-weight: 700; }
.booking-stepper__item.is-current .booking-stepper__marker { border-color: rgb(var(--v-theme-primary)); background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); box-shadow: 0 0 0 var(--ark-space-1) rgba(var(--v-theme-primary), .12); }
.booking-stepper__label { white-space: nowrap; }
.booking-progress__mobile { display: none; }
.booking-card__body { padding: var(--ark-space-5); }
.booking-section { padding: 0; }
.booking-section__title { margin: 0 0 var(--ark-space-4); font-weight: 700; }
.service-grid { display: grid; gap: var(--ark-space-3); }
.service-card { display: grid; grid-template-columns: 88px 1fr auto; align-items: center; gap: var(--ark-space-4); width: 100%; padding: var(--ark-space-3); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: var(--ark-radius-md); background: rgb(var(--v-theme-surface)); color: inherit; text-align: left; cursor: pointer; }
.service-card.is-selected { border-color: rgb(var(--v-theme-primary)); background: rgb(var(--v-theme-brand-soft)); box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary)); }
.service-card__image { display: grid; aspect-ratio: 4 / 3; place-items: center; border-radius: var(--ark-radius-sm); background: rgb(var(--v-theme-surface-light)); color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); }
.service-card__content { display: grid; gap: var(--ark-space-1); }
.service-card__content span { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: .875rem; }
.payment-option { min-height: 72px; margin-bottom: var(--ark-space-3); padding: var(--ark-space-3); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: var(--ark-radius-md); }
.booking-actions { display: flex; align-items: stretch; gap: var(--ark-space-3); margin-top: var(--ark-space-5); }
.booking-back-action { min-height: 44px; }
.booking-primary-action { flex: 1 1 auto; min-height: 44px; }
.reservation-summary { margin-bottom: var(--ark-space-5); overflow: hidden; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgb(var(--v-theme-surface-light)); }
.reservation-summary :deep(.v-list-item:not(:last-child)) { border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
@media (max-width: 599px) {
    .booking-card__header { padding: var(--ark-space-4) var(--ark-space-4) var(--ark-space-3); }
    .booking-progress { padding: 0 var(--ark-space-4) var(--ark-space-4); }
    .booking-stepper { display: none; }
    .booking-progress__mobile { display: block; }
    .booking-progress__mobile-label { display: flex; align-items: baseline; justify-content: space-between; gap: var(--ark-space-3); margin-bottom: var(--ark-space-2); color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: .8125rem; }
    .booking-progress__mobile-label strong { color: rgb(var(--v-theme-on-surface)); font-size: .875rem; }
    .booking-card__body { padding: var(--ark-space-4); }
    .booking-actions { flex-direction: column; gap: var(--ark-space-2); }
    .booking-actions :deep(.v-btn) { width: 100%; min-height: 48px; }
    .service-card { grid-template-columns: 72px 1fr auto; gap: var(--ark-space-3); }
    .reservation-summary :deep(.v-list-item) { padding-inline: var(--ark-space-4); }
}
</style>
