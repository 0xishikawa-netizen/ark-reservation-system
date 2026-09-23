<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { WeeklyAvailabilityTimetable } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: CustomerLayout });

interface StaffOption { id: number; display_name: string }
interface ServiceOption {
    id: number;
    name: string;
    duration_min: number;
    price: number;
    color: string | null;
    staff: StaffOption[];
}
interface TicketWalletOption {
    id: number;
    product_name: string;
    available: number;
    expires_at: string;
}
interface TicketAvailability { available_total: number; wallets: TicketWalletOption[] }
interface MembershipAvailability { available: number; status: string | null }
type PaymentMethod = 'onsite' | 'ticket' | 'card' | 'membership';

const props = defineProps<{
    services: ServiceOption[];
    ticket: TicketAvailability;
    membership: MembershipAvailability;
}>();
const stepNames = ['メニュー', 'スタッフ', '日時', 'お支払い', '確認'] as const;
const step = ref(1);
const submitErrorStep = ref<number | null>(null);
const bookableMembershipStatuses = ['active', 'grace', 'canceling'];
const canUseMembership = computed(() =>
    props.membership.available >= 1
        && props.membership.status !== null
        && bookableMembershipStatuses.includes(props.membership.status),
);
const serviceId = ref<number | null>(null);
const staffId = ref<number | null>(null);
const selectedStartsAt = ref<string | null>(null);
const form = useForm({
    service_id: null as number | null,
    staff_id: null as number | null,
    starts_at: null as string | null,
    payment_method: 'onsite' as PaymentMethod,
    reservation: null as string | null,
});
const selectedService = computed(() =>
    props.services.find((service) => service.id === serviceId.value) ?? null,
);
const staffItems = computed<Array<{ title: string; value: number | null }>>(() => [
    { title: '指名なし', value: null },
    ...(selectedService.value?.staff.map((staff) => ({
        title: staff.display_name,
        value: staff.id,
    })) ?? []),
]);
const selectedStaffName = computed(() => staffId.value === null
    ? '指名なし（空いているスタッフを自動割当）'
    : selectedService.value?.staff.find((staff) => staff.id === staffId.value)?.display_name ?? '未選択');
const paymentMethodAvailable = computed(() => {
    if (form.payment_method === 'ticket') return props.ticket.available_total >= 1;
    if (form.payment_method === 'membership') return canUseMembership.value;
    return ['onsite', 'card'].includes(form.payment_method);
});
const canSubmit = computed(() =>
    serviceId.value !== null
        && selectedStartsAt.value !== null
        && paymentMethodAvailable.value,
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
            return paymentMethodAvailable.value;
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

function formatPrice(price: number): string {
    return `${new Intl.NumberFormat('ja-JP').format(price)}円`;
}
function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        month: 'long', day: 'numeric', weekday: 'short', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));
}
function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric', month: 'long', day: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
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
    form.post('/reserve', {
        errorBag: 'reservation',
        onError: (errors) => {
            const fieldSteps: Record<string, number> = {
                service_id: 1,
                staff_id: 2,
                starts_at: 3,
                payment_method: 4,
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
    <Head title="予約する" />
    <v-card class="reserve-card mx-auto" max-width="720">
        <div class="reserve-card__header">
            <v-card-title class="text-h5 pa-0">予約する</v-card-title>
            <v-card-subtitle class="pa-0 mt-1">画面に沿って予約内容を選択してください</v-card-subtitle>
        </div>

        <nav v-if="services.length > 0" class="reserve-progress" aria-label="予約手順">
            <ol class="reserve-stepper">
                <li
                    v-for="(stepName, index) in stepNames"
                    :key="stepName"
                    class="reserve-stepper__item"
                    :class="{ 'is-current': step === index + 1, 'is-complete': step > index + 1 }"
                    :aria-current="step === index + 1 ? 'step' : undefined"
                >
                    <span class="reserve-stepper__marker" aria-hidden="true">
                        <span v-if="step > index + 1">✓</span>
                        <span v-else>{{ index + 1 }}</span>
                    </span>
                    <span class="reserve-stepper__label">{{ stepName }}</span>
                </li>
            </ol>
            <div class="reserve-progress__mobile" aria-live="polite">
                <div class="reserve-progress__mobile-label">
                    <span>ステップ {{ step }} / {{ stepNames.length }}</span>
                    <strong>{{ currentStepName }}</strong>
                </div>
                <v-progress-linear :model-value="progress" color="primary" height="4" rounded />
            </div>
        </nav>

        <v-card-text class="reserve-card__body">
            <v-alert v-if="services.length === 0" type="info" variant="tonal">
                {{ MESSAGES.menu.noneOnlineService }}
            </v-alert>
            <template v-else>
                <v-alert
                    v-if="submitErrorStep === step"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >{{ MESSAGES.common.checkInputPolite }}</v-alert>
                <section v-if="step === 1" class="reserve-section" aria-labelledby="member-service-step">
                    <h2 id="member-service-step" class="reserve-section__title text-subtitle-1">メニューを選択</h2>
                    <div class="service-grid">
                        <button
                            v-for="service in services" :key="service.id" type="button"
                            class="service-card" :class="{ 'is-selected': serviceId === service.id }"
                            :aria-pressed="serviceId === service.id" @click="serviceId = service.id"
                        >
                            <span class="service-card__image" aria-hidden="true"><v-icon icon="mdi-image-outline" size="32" /></span>
                            <span class="service-card__content">
                                <strong>{{ service.name }}</strong>
                                <span>{{ service.duration_min }}分 / {{ formatPrice(service.price) }}</span>
                            </span>
                            <v-icon v-if="serviceId === service.id" icon="mdi-check-circle" color="primary" size="24" />
                        </button>
                    </div>
                    <div v-if="form.errors.service_id" class="text-error text-body-2 mt-2">{{ form.errors.service_id }}</div>
                    <div class="reserve-actions">
                        <v-btn color="primary" size="large" class="reserve-primary-action" :disabled="!canAdvance" @click="nextStep">次へ</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 2" class="reserve-section" aria-labelledby="member-staff-step">
                    <h2 id="member-staff-step" class="reserve-section__title text-subtitle-1">スタッフを選択</h2>
                    <v-select
                        v-model="staffId" :items="staffItems" label="担当スタッフ" persistent-hint
                        hint="指名なしの場合は、予約確定時に空いているスタッフを割り当てます。"
                        :error-messages="form.errors.staff_id"
                    />
                    <div class="reserve-actions">
                        <v-btn class="reserve-back-action" variant="text" @click="previousStep">戻る</v-btn>
                        <v-btn color="primary" size="large" class="reserve-primary-action" :disabled="!canAdvance" @click="nextStep">次へ</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 3" class="reserve-section" aria-labelledby="member-datetime-step">
                    <h2 id="member-datetime-step" class="reserve-section__title text-subtitle-1">日時を選択</h2>
                    <WeeklyAvailabilityTimetable
                        v-if="selectedService"
                        v-model="selectedStartsAt" :service-id="selectedService.id" :staff-id="staffId"
                        week-endpoint="/reserve/availability/week"
                    />
                    <div v-if="form.errors.starts_at" class="text-error text-body-2 mt-2">{{ form.errors.starts_at }}</div>
                    <div class="reserve-actions">
                        <v-btn class="reserve-back-action" variant="text" @click="previousStep">戻る</v-btn>
                        <v-btn color="primary" size="large" class="reserve-primary-action" :disabled="!canAdvance" @click="nextStep">次へ</v-btn>
                    </div>
                </section>

                <section v-else-if="step === 4" class="reserve-section" aria-labelledby="member-payment-step">
                    <h2 id="member-payment-step" class="reserve-section__title text-subtitle-1">お支払い方法を選択</h2>
                    <v-radio-group v-model="form.payment_method" :error-messages="form.errors.payment_method">
                        <v-radio label="店頭でお支払い" value="onsite" />
                        <v-radio label="クレジットカードで事前に支払う" value="card" />
                        <v-radio :label="`回数券を使う（残り ${ticket.available_total} 回）`" value="ticket" :disabled="ticket.available_total < 1" />
                        <v-radio :label="`利用権を使う（当期残り ${membership.available} 回）`" value="membership" :disabled="!canUseMembership" />
                    </v-radio-group>
                    <v-alert v-if="form.payment_method === 'card'" type="info" variant="tonal" density="comfortable" class="mb-4">
                        {{ MESSAGES.payment.cardAfterReserve }}
                    </v-alert>
                    <v-alert v-if="ticket.available_total < 1" type="info" variant="tonal" density="compact" class="mb-4">
                        {{ MESSAGES.ticket.noneSelectable }}
                    </v-alert>
                    <v-alert v-else-if="form.payment_method === 'ticket'" type="info" variant="tonal" density="compact" class="mb-4">
                        <div v-for="wallet in ticket.wallets" :key="wallet.id">
                            {{ wallet.product_name }}：{{ wallet.available }}回（有効期限 {{ formatDate(wallet.expires_at) }}）
                        </div>
                    </v-alert>
                    <v-alert v-if="!canUseMembership" type="info" variant="tonal" density="compact" class="mb-4">
                        {{ MESSAGES.membership.notSelectable }}
                    </v-alert>
                    <v-alert v-else-if="form.payment_method === 'membership'" type="info" variant="tonal" density="compact" class="mb-4">
                        {{ MESSAGES.membership.usesOnePerPeriod }}
                    </v-alert>
                    <div class="reserve-actions">
                        <v-btn class="reserve-back-action" variant="text" @click="previousStep">戻る</v-btn>
                        <v-btn color="primary" size="large" class="reserve-primary-action" :disabled="!canAdvance" @click="nextStep">次へ</v-btn>
                    </div>
                </section>

                <section v-else class="reserve-section" aria-labelledby="member-confirm-step">
                    <h2 id="member-confirm-step" class="reserve-section__title text-subtitle-1">予約内容を確認</h2>
                    <v-card class="reservation-summary" variant="flat">
                        <v-list lines="two" bg-color="transparent">
                            <v-list-item title="メニュー" :subtitle="selectedService?.name" />
                            <v-list-item title="所要時間・料金" :subtitle="`${selectedService?.duration_min}分 / ${formatPrice(selectedService?.price ?? 0)}`" />
                            <v-list-item title="担当" :subtitle="selectedStaffName" />
                            <v-list-item title="日時" :subtitle="selectedStartsAt ? formatDateTime(selectedStartsAt) : ''" />
                        </v-list>
                    </v-card>
                    <v-alert v-if="form.errors.reservation" type="error" variant="tonal" class="mb-4">{{ form.errors.reservation }}</v-alert>
                    <div class="reserve-actions">
                        <v-btn class="reserve-back-action" variant="text" :disabled="form.processing" @click="previousStep">戻る</v-btn>
                        <v-btn color="primary" size="large" class="reserve-primary-action" :loading="form.processing" :disabled="!canSubmit" @click="submit">予約する</v-btn>
                    </div>
                </section>
            </template>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.reserve-card__header { padding: var(--ark-space-5) var(--ark-space-5) var(--ark-space-4); }
.reserve-progress { padding: 0 var(--ark-space-5) var(--ark-space-5); border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.reserve-stepper { display: flex; padding: 0; margin: 0; list-style: none; }
.reserve-stepper__item { position: relative; display: flex; flex: 1 1 0; flex-direction: column; align-items: center; gap: var(--ark-space-2); min-width: 0; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: .75rem; line-height: 1.4; text-align: center; }
.reserve-stepper__item:not(:last-child)::after { position: absolute; z-index: 0; top: 15px; left: calc(50% + 18px); width: calc(100% - 36px); height: 2px; background: rgba(var(--v-border-color), var(--v-border-opacity)); content: ''; }
.reserve-stepper__item.is-complete:not(:last-child)::after { background: rgb(var(--v-theme-primary)); }
.reserve-stepper__marker { position: relative; z-index: 1; display: grid; width: 32px; height: 32px; place-items: center; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 50%; background: rgb(var(--v-theme-surface-light)); font-weight: 700; }
.reserve-stepper__item.is-complete { color: rgb(var(--v-theme-primary)); }
.reserve-stepper__item.is-complete .reserve-stepper__marker { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .12); }
.reserve-stepper__item.is-current { color: rgb(var(--v-theme-on-surface)); font-weight: 700; }
.reserve-stepper__item.is-current .reserve-stepper__marker { border-color: rgb(var(--v-theme-primary)); background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); box-shadow: 0 0 0 var(--ark-space-1) rgba(var(--v-theme-primary), .12); }
.reserve-stepper__label { white-space: nowrap; }
.reserve-progress__mobile { display: none; }
.reserve-card__body { padding: var(--ark-space-5); }
.reserve-section { padding: 0; }
.reserve-section__title { margin: 0 0 var(--ark-space-4); font-weight: 700; }
.service-grid { display: grid; gap: var(--ark-space-3); }
.service-card { display: grid; grid-template-columns: 88px 1fr auto; align-items: center; gap: var(--ark-space-4); width: 100%; padding: var(--ark-space-3); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: var(--ark-radius-md); background: rgb(var(--v-theme-surface)); color: inherit; text-align: left; cursor: pointer; }
.service-card.is-selected { border-color: rgb(var(--v-theme-primary)); background: rgb(var(--v-theme-brand-soft)); box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary)); }
.service-card__image { display: grid; aspect-ratio: 4 / 3; place-items: center; border-radius: var(--ark-radius-sm); background: rgb(var(--v-theme-surface-light)); color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); }
.service-card__content { display: grid; gap: var(--ark-space-1); }
.service-card__content span { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: .875rem; }
.reserve-actions { display: flex; align-items: stretch; gap: var(--ark-space-3); margin-top: var(--ark-space-5); }
.reserve-back-action { min-height: 44px; }
.reserve-primary-action { flex: 1 1 auto; min-height: 44px; }
.reservation-summary { margin-bottom: var(--ark-space-5); overflow: hidden; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgb(var(--v-theme-surface-light)); box-shadow: none; }
.reservation-summary :deep(.v-list-item:not(:last-child)) { border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
@media (max-width: 599px) {
    .reserve-card__header { padding: var(--ark-space-4) var(--ark-space-4) var(--ark-space-3); }
    .reserve-progress { padding: 0 var(--ark-space-4) var(--ark-space-4); }
    .reserve-stepper { display: none; }
    .reserve-progress__mobile { display: block; }
    .reserve-progress__mobile-label { display: flex; align-items: baseline; justify-content: space-between; gap: var(--ark-space-3); margin-bottom: var(--ark-space-2); color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); font-size: .8125rem; }
    .reserve-progress__mobile-label strong { color: rgb(var(--v-theme-on-surface)); font-size: .875rem; }
    .reserve-card__body { padding: var(--ark-space-4); }
    .reserve-actions { flex-direction: column; gap: var(--ark-space-2); }
    .reserve-actions :deep(.v-btn) { width: 100%; min-height: 48px; }
    .service-card { grid-template-columns: 72px 1fr auto; gap: var(--ark-space-3); }
    .reservation-summary :deep(.v-list-item) { padding-inline: var(--ark-space-4); }
}
</style>
