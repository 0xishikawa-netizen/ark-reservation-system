<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { DateField, EmptyValue, MoneyField, MonthField, PageHeader, SectionCard, StatusChip, TimeField } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { formatNumber } from '@/utils/money';

defineOptions({ layout: AdminLayout });

// 税率の基準値と表示精度。
const DEFAULT_TAX_RATE_BPS = 1000;
const BASIS_POINTS_PER_PERCENT = 100;
const TAX_RATE_DECIMAL_PLACES = 2;
// ISO 日付部分（YYYY-MM-DD）の文字数。
const ISO_DATE_LENGTH = 10;
// カルテ選択肢・資格の新規項目に使う既定表示順。
const DEFAULT_KARTE_SORT_ORDER = 100;
interface Master { id: number; code: string; name: string; is_active: boolean; sort_order: number }
interface TaxRate { id: number; tax_category_id: number; rate_bps: number; effective_from: string; effective_to: string | null }
interface TaxCategory extends Master { rates: TaxRate[] }
interface PaymentMethod { id: number; code: string; name: string; is_enabled: boolean; display_order: number; external_provider: string | null }
interface CalendarDay { id: number; business_date: string; status: 'closed' | 'special_hours' | 'open'; opens_at: string | null; closes_at: string | null; note: string | null }
interface MonthlyTarget { id: number; target_month: string; target_amount: number }
const props = defineProps<{
    analysisCategories: Master[]; taxCategories: TaxCategory[]; paymentMethods: PaymentMethod[];
    calendarDays: CalendarDay[]; salesTargets: { default_amount: number; monthly: MonthlyTarget[] };
    employmentTypes: Master[]; closedWeekdays?: number[]; acquisitionChannels?: Master[]; visitPurposes?: Master[]; qualifications?: Master[]; business: { timezone: string; default_opens_at: string; default_closes_at: string };
}>();
const tab = ref('analysis');
const editingRateId = ref<number | null>(null);
const editingPaymentId = ref<number | null>(null);
const masterForm = useForm({ code: '', name: '', is_active: true, sort_order: 0 });
const taxCategoryForm = useForm({ code: '', name: '', is_active: true, sort_order: 0 });
const rateForm = useForm({ tax_category_id: null as number | null, rate_bps: DEFAULT_TAX_RATE_BPS, effective_from: '', effective_to: null as string | null });
/** 税率は % で入力し、保存時に bp（1% = 100bp）へ直す。 */
const ratePercent = computed<number | null>({
    get: () => rateForm.rate_bps / BASIS_POINTS_PER_PERCENT,
    set: (value) => { rateForm.rate_bps = Math.round((value ?? 0) * BASIS_POINTS_PER_PERCENT); },
});
const paymentForm = useForm({ code: '', name: '', is_enabled: true, display_order: 0, external_provider: null as string | null });
/** 決済方法の変更（ダイアログ）用。追加フォームと入力を共有しない。 */
const paymentEditForm = useForm({ code: '', name: '', is_enabled: true, display_order: 0, external_provider: null as string | null });
const calendarForm = useForm({ business_date: '', status: 'closed' as 'closed' | 'special_hours' | 'open', opens_at: null as string | null, closes_at: null as string | null, note: null as string | null });
const closedWeekdaysForm = useForm({ weekdays: [...(props.closedWeekdays ?? [])] });
const weekdayOptions = [
    { value: 1, label: MESSAGES.mastersUi.businessMasters.weekdays[0] }, { value: 2, label: MESSAGES.mastersUi.businessMasters.weekdays[1] },
    { value: 3, label: MESSAGES.mastersUi.businessMasters.weekdays[2] }, { value: 4, label: MESSAGES.mastersUi.businessMasters.weekdays[3] },
    { value: 5, label: MESSAGES.mastersUi.businessMasters.weekdays[4] }, { value: 6, label: MESSAGES.mastersUi.businessMasters.weekdays[5] },
    { value: 7, label: MESSAGES.mastersUi.businessMasters.weekdays[6] },
];
const calendarStatusItems = [
    { title: MESSAGES.mastersUi.businessMasters.calendarStatuses.closed, value: 'closed' },
    { title: MESSAGES.mastersUi.businessMasters.calendarStatuses.specialHours, value: 'special_hours' },
    { title: MESSAGES.mastersUi.businessMasters.calendarStatuses.open, value: 'open' },
];
const calendarStatusLabel = (status: CalendarDay['status']): string => calendarStatusItems.find((item) => item.value === status)?.title ?? status;
const saveCalendarDay = (): void => calendarForm.put('/admin/settings/business-masters/calendar', { preserveScroll: true, onSuccess: () => calendarForm.reset() });
const defaultTargetForm = useForm({ target_amount: props.salesTargets.default_amount as number | null });
const monthlyTargetForm = useForm({ target_month: '', target_amount: null as number | null });
const employmentForm = useForm({ code: '', name: '', is_active: true, sort_order: 0 });
const saveNewMaster = (form: typeof masterForm, path: string): void => form.post(path, { preserveScroll: true, onSuccess: () => form.reset() });
const pendingToggleKeys = reactive(new Set<string>());
const toggleKey = (path: string, id: number): string => `${path}:${id}`;
const isTogglePending = (path: string, id: number): boolean => pendingToggleKeys.has(toggleKey(path, id));
const toggleMaster = (item: Master, path: string, active: boolean | null): void => {
    const key = toggleKey(path, item.id);
    if (active === null || active === item.is_active || pendingToggleKeys.has(key)) return;
    pendingToggleKeys.add(key);
    router.put(`${path}/${item.id}`, { ...item, is_active: active }, {
        preserveScroll: true,
        onFinish: () => pendingToggleKeys.delete(key),
    });
};
const paymentPath = '/admin/settings/business-masters/payment-methods';
const togglePayment = (item: PaymentMethod, enabled: boolean | null): void => {
    const key = toggleKey(paymentPath, item.id);
    if (enabled === null || enabled === item.is_enabled || pendingToggleKeys.has(key)) return;
    pendingToggleKeys.add(key);
    router.put(`${paymentPath}/${item.id}`, { ...item, is_enabled: enabled }, {
        preserveScroll: true,
        onFinish: () => pendingToggleKeys.delete(key),
    });
};
/** 日付（YYYY-MM-DD やタイムスタンプ）を YYYY/MM/DD で表示する。 */
const displayDate = (value: string | null): string => (value ? value.slice(0, ISO_DATE_LENGTH).replaceAll('-', '/') : '');
const ratePercentLabel = (bps: number): string => `${(bps / BASIS_POINTS_PER_PERCENT).toFixed(bps % BASIS_POINTS_PER_PERCENT === 0 ? 0 : TAX_RATE_DECIMAL_PLACES)}%`;
// 税率の追加・変更はダイアログで行う（一覧の下のフォームだと、どの行を編集中か分かりにくいため）。
const rateDialogOpen = ref(false);
const openRateDialog = (category: TaxCategory, rate: TaxRate | null = null): void => {
    rateForm.clearErrors();
    editingRateId.value = rate?.id ?? null;
    rateForm.tax_category_id = category.id;
    rateForm.rate_bps = rate?.rate_bps ?? DEFAULT_TAX_RATE_BPS;
    rateForm.effective_from = rate ? rate.effective_from.slice(0, ISO_DATE_LENGTH) : '';
    rateForm.effective_to = rate?.effective_to ? rate.effective_to.slice(0, ISO_DATE_LENGTH) : null;
    rateDialogOpen.value = true;
};
const closeRateDialog = (): void => {
    rateDialogOpen.value = false;
    editingRateId.value = null;
    rateForm.reset();
};
const rateDialogCategoryName = computed(() => props.taxCategories.find((c) => c.id === rateForm.tax_category_id)?.name ?? '');
const saveRate = (): void => {
    const options = { preserveScroll: true, onSuccess: (): void => closeRateDialog() };
    if (editingRateId.value === null) rateForm.post('/admin/settings/business-masters/tax-rates', options);
    else rateForm.put(`/admin/settings/business-masters/tax-rates/${editingRateId.value}`, options);
};
const paymentDialogOpen = ref(false);
const closePaymentDialog = (): void => {
    paymentDialogOpen.value = false;
    editingPaymentId.value = null;
    paymentEditForm.reset();
};
const editPayment = (item: PaymentMethod): void => {
    paymentEditForm.clearErrors();
    editingPaymentId.value = item.id;
    paymentEditForm.code = item.code;
    paymentEditForm.name = item.name;
    paymentEditForm.is_enabled = item.is_enabled;
    paymentEditForm.display_order = item.display_order;
    paymentEditForm.external_provider = item.external_provider;
    paymentDialogOpen.value = true;
};
const addPayment = (): void => paymentForm.post('/admin/settings/business-masters/payment-methods', { preserveScroll: true, onSuccess: () => paymentForm.reset() });
const updatePayment = (): void => {
    if (editingPaymentId.value === null) return;
    paymentEditForm.put(`/admin/settings/business-masters/payment-methods/${editingPaymentId.value}`, { preserveScroll: true, onSuccess: () => closePaymentDialog() });
};
const karteForms = {
    'acquisition-channels': useForm({ code: '', name: '', is_active: true, sort_order: DEFAULT_KARTE_SORT_ORDER }),
    'visit-purposes': useForm({ code: '', name: '', is_active: true, sort_order: DEFAULT_KARTE_SORT_ORDER }),
    qualifications: useForm({ code: '', name: '', is_active: true, sort_order: DEFAULT_KARTE_SORT_ORDER }),
};
type KarteKind = keyof typeof karteForms;
const karteBase = '/admin/settings/business-masters/karte';
const karteLists = (kind: KarteKind): Master[] => (kind === 'acquisition-channels' ? props.acquisitionChannels
    : kind === 'qualifications' ? props.qualifications : props.visitPurposes) ?? [];
/** 並び替えは隣の行の表示順の前後へ移すだけ（削除はせず無効化で管理）。 */
const moveKarte = (kind: KarteKind, item: Master, direction: -1 | 1): void => {
    const list = karteLists(kind);
    const neighbor = list[list.findIndex((row) => row.id === item.id) + direction];
    if (!neighbor) return;
    router.put(`${karteBase}/${kind}/${item.id}`, { ...item, sort_order: neighbor.sort_order + direction }, { preserveScroll: true });
};
</script>

<template>
    <Head :title="MESSAGES.mastersUi.businessMasters.title" />
    <PageHeader :title="MESSAGES.mastersUi.businessMasters.title" :subtitle="fillMessage(MESSAGES.mastersUi.businessMasters.subtitle, { timezone: business.timezone })" />
    <v-tabs v-model="tab" class="mb-4" show-arrows color="primary">
        <v-tab value="analysis">{{ MESSAGES.mastersUi.businessMasters.tabs.analysis }}</v-tab>
        <v-tab value="tax">{{ MESSAGES.mastersUi.businessMasters.tabs.tax }}</v-tab>
        <v-tab value="payment">{{ MESSAGES.mastersUi.businessMasters.tabs.payment }}</v-tab>
        <v-tab value="calendar">{{ MESSAGES.mastersUi.businessMasters.tabs.calendar }}</v-tab>
        <v-tab value="target">{{ MESSAGES.mastersUi.businessMasters.tabs.target }}</v-tab>
        <v-tab value="employment">{{ MESSAGES.mastersUi.businessMasters.tabs.employment }}</v-tab>
        <v-tab value="karte">{{ MESSAGES.customer.karteMasterTab }}</v-tab>
        <v-tab value="qualification">{{ MESSAGES.bookingResources.qualificationMasterTab }}</v-tab>
    </v-tabs>

    <v-window v-model="tab">
        <!-- メニュー分類 -->
        <v-window-item value="analysis">
            <SectionCard :title="MESSAGES.mastersUi.businessMasters.analysisTitle" :subtitle="MESSAGES.mastersUi.businessMasters.analysisSubtitle">
                <v-table class="bm-table">
                    <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.code }}</th><th>{{ MESSAGES.mastersUi.businessMasters.name }}</th><th>{{ MESSAGES.mastersUi.businessMasters.active }}</th></tr></thead>
                    <tbody>
                        <tr v-for="item in analysisCategories" :key="item.id">
                            <td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_active" color="primary" hide-details density="compact" :aria-label="fillMessage(MESSAGES.mastersUi.businessMasters.activeState, { name: item.name })" :disabled="isTogglePending('/admin/settings/business-masters/analysis-categories', item.id)" @update:model-value="toggleMaster(item, '/admin/settings/business-masters/analysis-categories', $event)" /></td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.addCategory }}</p>
                    <v-form class="bm-form" @submit.prevent="saveNewMaster(masterForm, '/admin/settings/business-masters/analysis-categories')">
                        <v-text-field v-model="masterForm.code" :label="MESSAGES.mastersUi.businessMasters.code" hide-details="auto" class="bm-field bm-field--s" :error-messages="masterForm.errors.code" />
                        <v-text-field v-model="masterForm.name" :label="MESSAGES.mastersUi.businessMasters.name" hide-details="auto" class="bm-field" :error-messages="masterForm.errors.name" />
                        <v-text-field v-model.number="masterForm.sort_order" :label="MESSAGES.mastersUi.businessMasters.sortOrder" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="masterForm.processing">{{ MESSAGES.mastersUi.businessMasters.add }}</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- 税 -->
        <v-window-item value="tax">
            <SectionCard :title="MESSAGES.mastersUi.businessMasters.taxTitle" :subtitle="MESSAGES.mastersUi.businessMasters.taxSubtitle">
                <v-table class="bm-table">
                    <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.taxCategory }}</th><th>{{ MESSAGES.mastersUi.businessMasters.taxRatePeriod }}</th><th>{{ MESSAGES.mastersUi.businessMasters.active }}</th><th class="text-end">{{ MESSAGES.mastersUi.businessMasters.operation }}</th></tr></thead>
                    <tbody>
                        <tr v-for="category in taxCategories" :key="category.id">
                            <td><strong>{{ category.name }}</strong><div class="text-caption text-medium-emphasis">{{ category.code }}</div></td>
                            <td>
                                <div v-for="rate in category.rates" :key="rate.id" class="bm-rate">
                                    <span class="bm-rate__value">{{ ratePercentLabel(rate.rate_bps) }}</span>
                                    <span class="text-medium-emphasis">{{ displayDate(rate.effective_from) }} 〜 {{ rate.effective_to ? displayDate(rate.effective_to) : MESSAGES.mastersUi.businessMasters.noExpiry }}</span>
                                    <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" @click="openRateDialog(category, rate)">{{ MESSAGES.mastersUi.businessMasters.edit }}</v-btn>
                                </div>
                                <span v-if="category.rates.length === 0" class="text-error text-body-2">{{ MESSAGES.settings.taxRateMissing }}</span>
                            </td>
                            <td><v-switch :model-value="category.is_active" color="primary" hide-details density="compact" :aria-label="fillMessage(MESSAGES.mastersUi.businessMasters.activeState, { name: category.name })" :disabled="isTogglePending('/admin/settings/business-masters/tax-categories', category.id)" @update:model-value="toggleMaster(category, '/admin/settings/business-masters/tax-categories', $event)" /></td>
                            <td class="text-end"><v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="openRateDialog(category)">{{ MESSAGES.mastersUi.businessMasters.addTaxRate }}</v-btn></td>
                        </tr>
                    </tbody>
                </v-table>
                <!-- 税率の追加・変更ダイアログ -->
                <v-dialog v-model="rateDialogOpen" max-width="440" @update:model-value="(open) => { if (!open) closeRateDialog(); }">
                    <v-card>
                        <v-card-title class="text-subtitle-1 font-weight-bold">{{ editingRateId === null ? MESSAGES.mastersUi.businessMasters.addTaxRate : MESSAGES.mastersUi.businessMasters.changeTaxRate }}（{{ rateDialogCategoryName }}）</v-card-title>
                        <v-card-text class="bm-dialog">
                            <v-select v-model="rateForm.tax_category_id" :items="taxCategories" item-title="name" item-value="id" :label="MESSAGES.mastersUi.businessMasters.taxCategory" disabled hide-details="auto" :error-messages="rateForm.errors.tax_category_id" />
                            <v-text-field v-model.number="ratePercent" :label="MESSAGES.mastersUi.businessMasters.taxRate" suffix="%" type="number" step="0.01" min="0" hide-details="auto" :error-messages="rateForm.errors.rate_bps" />
                            <DateField v-model="rateForm.effective_from" :label="MESSAGES.mastersUi.businessMasters.startDate" :clearable="false" block hide-details="auto" :error-messages="rateForm.errors.effective_from" />
                            <DateField :model-value="rateForm.effective_to ?? ''" :label="MESSAGES.mastersUi.businessMasters.endDateOptional" block hide-details="auto" :error-messages="rateForm.errors.effective_to" @update:model-value="rateForm.effective_to = $event || null" />
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn variant="text" :disabled="rateForm.processing" @click="closeRateDialog">{{ MESSAGES.mastersUi.businessMasters.cancel }}</v-btn>
                            <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="rateForm.processing" @click="saveRate">{{ MESSAGES.mastersUi.businessMasters.save }}</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
                <div class="bm-add">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.addTaxCategory }}</p>
                    <v-form class="bm-form" @submit.prevent="taxCategoryForm.post('/admin/settings/business-masters/tax-categories', { preserveScroll: true, onSuccess: () => taxCategoryForm.reset() })">
                        <v-text-field v-model="taxCategoryForm.code" :label="MESSAGES.mastersUi.businessMasters.code" hide-details="auto" class="bm-field bm-field--s" :error-messages="taxCategoryForm.errors.code" />
                        <v-text-field v-model="taxCategoryForm.name" :label="MESSAGES.mastersUi.businessMasters.name" hide-details="auto" class="bm-field" :error-messages="taxCategoryForm.errors.name" />
                        <v-text-field v-model.number="taxCategoryForm.sort_order" :label="MESSAGES.mastersUi.businessMasters.sortOrder" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="taxCategoryForm.processing">{{ MESSAGES.mastersUi.businessMasters.add }}</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- 決済方法 -->
        <v-window-item value="payment">
            <SectionCard :title="MESSAGES.mastersUi.businessMasters.paymentTitle" :subtitle="MESSAGES.mastersUi.businessMasters.paymentSubtitle">
                <v-table class="bm-table">
                    <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.sortOrder }}</th><th>{{ MESSAGES.mastersUi.businessMasters.code }}</th><th>{{ MESSAGES.mastersUi.businessMasters.name }}</th><th>{{ MESSAGES.mastersUi.businessMasters.active }}</th><th class="text-end">{{ MESSAGES.mastersUi.businessMasters.operation }}</th></tr></thead>
                    <tbody>
                        <tr v-for="item in paymentMethods" :key="item.id">
                            <td>{{ item.display_order }}</td><td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_enabled" color="primary" hide-details density="compact" :aria-label="fillMessage(MESSAGES.mastersUi.businessMasters.activeState, { name: item.name })" :disabled="isTogglePending(paymentPath, item.id)" @update:model-value="togglePayment(item, $event)" /></td>
                            <td class="text-end"><v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" @click="editPayment(item)">{{ MESSAGES.mastersUi.businessMasters.edit }}</v-btn></td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.addPayment }}</p>
                    <v-form class="bm-form" @submit.prevent="addPayment">
                        <v-text-field v-model="paymentForm.code" :label="MESSAGES.mastersUi.businessMasters.code" hide-details="auto" class="bm-field bm-field--s" :error-messages="paymentForm.errors.code" />
                        <v-text-field v-model="paymentForm.name" :label="MESSAGES.mastersUi.businessMasters.name" hide-details="auto" class="bm-field" :error-messages="paymentForm.errors.name" />
                        <v-text-field v-model.number="paymentForm.display_order" :label="MESSAGES.mastersUi.businessMasters.sortOrder" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-text-field v-model="paymentForm.external_provider" :label="MESSAGES.mastersUi.businessMasters.externalProviderOptional" hide-details="auto" class="bm-field bm-field--s" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="paymentForm.processing">{{ MESSAGES.mastersUi.businessMasters.add }}</v-btn>
                    </v-form>
                </div>
                <!-- 決済方法の変更ダイアログ -->
                <v-dialog v-model="paymentDialogOpen" max-width="440" @update:model-value="(open) => { if (!open) closePaymentDialog(); }">
                    <v-card>
                        <v-card-title class="text-subtitle-1 font-weight-bold">{{ MESSAGES.mastersUi.businessMasters.editPayment }}</v-card-title>
                        <v-card-text class="bm-dialog">
                            <v-text-field v-model="paymentEditForm.code" :label="MESSAGES.mastersUi.businessMasters.code" hide-details="auto" :error-messages="paymentEditForm.errors.code" />
                            <v-text-field v-model="paymentEditForm.name" :label="MESSAGES.mastersUi.businessMasters.name" hide-details="auto" :error-messages="paymentEditForm.errors.name" />
                            <v-text-field v-model.number="paymentEditForm.display_order" :label="MESSAGES.mastersUi.businessMasters.sortOrder" type="number" hide-details="auto" :error-messages="paymentEditForm.errors.display_order" />
                            <v-text-field v-model="paymentEditForm.external_provider" :label="MESSAGES.mastersUi.businessMasters.externalProviderOptional" hide-details="auto" :error-messages="paymentEditForm.errors.external_provider" />
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn variant="text" :disabled="paymentEditForm.processing" @click="closePaymentDialog">{{ MESSAGES.mastersUi.businessMasters.cancel }}</v-btn>
                            <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="paymentEditForm.processing" @click="updatePayment">{{ MESSAGES.mastersUi.businessMasters.save }}</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
            </SectionCard>
        </v-window-item>

        <!-- 店舗カレンダー -->
        <v-window-item value="calendar">
            <SectionCard :title="MESSAGES.mastersUi.businessMasters.regularHolidayTitle" :subtitle="fillMessage(MESSAGES.mastersUi.businessMasters.regularHolidaySubtitle, { open: business.default_opens_at, close: business.default_closes_at })" class="mb-4">
                <v-form class="bm-form" @submit.prevent="closedWeekdaysForm.put('/admin/settings/business-masters/calendar/closed-weekdays', { preserveScroll: true })">
                    <v-chip-group v-model="closedWeekdaysForm.weekdays" multiple column selected-class="bm-weekday--on" :aria-label="MESSAGES.mastersUi.businessMasters.regularHolidayAria">
                        <v-chip v-for="day in weekdayOptions" :key="day.value" :value="day.value" filter variant="outlined" class="bm-weekday">{{ day.label }}</v-chip>
                    </v-chip-group>
                    <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" class="bm-submit" :loading="closedWeekdaysForm.processing">{{ MESSAGES.mastersUi.businessMasters.save }}</v-btn>
                </v-form>
                <p class="text-body-2 text-medium-emphasis mt-2">{{ closedWeekdaysForm.weekdays.length === 0 ? MESSAGES.mastersUi.businessMasters.noRegularHoliday : fillMessage(MESSAGES.mastersUi.businessMasters.weeklyHoliday, { days: weekdayOptions.filter((d) => closedWeekdaysForm.weekdays.includes(d.value)).map((d) => d.label).join('・') }) }}</p>
            </SectionCard>

            <SectionCard :title="MESSAGES.mastersUi.businessMasters.specialDayTitle" :subtitle="MESSAGES.mastersUi.businessMasters.specialDaySubtitle">
                <v-form class="bm-form" @submit.prevent="saveCalendarDay">
                    <DateField v-model="calendarForm.business_date" :label="MESSAGES.mastersUi.businessMasters.date" :clearable="false" />
                    <v-select v-model="calendarForm.status" :label="MESSAGES.mastersUi.businessMasters.category" :items="calendarStatusItems" hide-details="auto" class="bm-field" />
                    <TimeField v-if="calendarForm.status === 'special_hours'" :model-value="calendarForm.opens_at ?? ''" :label="MESSAGES.mastersUi.businessMasters.opensAt" @update:model-value="calendarForm.opens_at = $event || null" />
                    <TimeField v-if="calendarForm.status === 'special_hours'" :model-value="calendarForm.closes_at ?? ''" :label="MESSAGES.mastersUi.businessMasters.closesAt" @update:model-value="calendarForm.closes_at = $event || null" />
                    <v-text-field v-model="calendarForm.note" :label="MESSAGES.mastersUi.businessMasters.noteOptional" hide-details="auto" class="bm-field" />
                    <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" class="bm-submit" :loading="calendarForm.processing">{{ MESSAGES.mastersUi.businessMasters.save }}</v-btn>
                </v-form>
                <p v-if="calendarForm.errors.closes_at || calendarForm.errors.business_date" class="text-error text-body-2 mt-2">{{ calendarForm.errors.closes_at || calendarForm.errors.business_date }}</p>
                <v-table class="bm-table mt-4">
                    <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.date }}</th><th>{{ MESSAGES.mastersUi.businessMasters.category }}</th><th>{{ MESSAGES.mastersUi.businessMasters.businessHours }}</th><th>{{ MESSAGES.mastersUi.businessMasters.note }}</th><th class="text-end">{{ MESSAGES.mastersUi.businessMasters.operation }}</th></tr></thead>
                    <tbody>
                        <tr v-for="day in calendarDays" :key="day.id">
                            <td>{{ day.business_date }}</td>
                            <td><StatusChip :status="day.status === 'closed' ? 'canceled' : 'active'" :label="calendarStatusLabel(day.status)" /></td>
                            <td>
                                <span v-if="day.opens_at && day.closes_at">{{ day.opens_at }}〜{{ day.closes_at }}</span>
                                <span v-else-if="day.status === 'open'">{{ business.default_opens_at }}〜{{ business.default_closes_at }}</span>
                                <EmptyValue v-else />
                            </td>
                            <td>{{ day.note || '' }}</td>
                            <td class="text-end"><v-btn size="small" variant="tonal" @click="router.delete(`/admin/settings/business-masters/calendar/${day.id}`, { preserveScroll: true })">{{ MESSAGES.mastersUi.businessMasters.cancelRegistration }}</v-btn></td>
                        </tr>
                        <tr v-if="calendarDays.length === 0"><td colspan="5" class="text-medium-emphasis">{{ MESSAGES.mastersUi.businessMasters.noRegistrations }}</td></tr>
                    </tbody>
                </v-table>
            </SectionCard>
        </v-window-item>

        <!-- 売上目標 -->
        <v-window-item value="target">
            <SectionCard :title="MESSAGES.mastersUi.businessMasters.salesTargetTitle" :subtitle="MESSAGES.mastersUi.businessMasters.salesTargetSubtitle">
                <div class="bm-add bm-add--first">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.defaultMonthlyTarget }}</p>
                    <v-form class="bm-form" @submit.prevent="defaultTargetForm.put('/admin/settings/business-masters/sales-target/default', { preserveScroll: true })">
                        <MoneyField v-model="defaultTargetForm.target_amount" :label="MESSAGES.mastersUi.businessMasters.monthlyTarget" hide-details="auto" class="bm-field" :error-messages="defaultTargetForm.errors.target_amount" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" class="bm-submit" :loading="defaultTargetForm.processing">{{ MESSAGES.mastersUi.businessMasters.save }}</v-btn>
                    </v-form>
                </div>
                <div class="bm-add">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.monthlyTargets }}</p>
                    <v-form class="bm-form" @submit.prevent="monthlyTargetForm.put('/admin/settings/business-masters/sales-target/monthly', { preserveScroll: true, onSuccess: () => monthlyTargetForm.reset() })">
                        <MonthField v-model="monthlyTargetForm.target_month" :label="MESSAGES.calendar.targetMonth" />
                        <MoneyField v-model="monthlyTargetForm.target_amount" :label="MESSAGES.mastersUi.businessMasters.target" hide-details="auto" class="bm-field" :error-messages="monthlyTargetForm.errors.target_amount" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="monthlyTargetForm.processing">{{ MESSAGES.mastersUi.businessMasters.set }}</v-btn>
                    </v-form>
                    <v-table class="bm-table mt-4">
                        <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.targetMonth }}</th><th class="text-end">{{ MESSAGES.mastersUi.businessMasters.target }}</th><th class="text-end">{{ MESSAGES.mastersUi.businessMasters.operation }}</th></tr></thead>
                        <tbody>
                            <tr v-for="item in salesTargets.monthly" :key="item.id">
                                <td>{{ item.target_month }}</td>
                                <td class="text-end">{{ fillMessage(MESSAGES.mastersUi.businessMasters.yenValue, { amount: formatNumber(item.target_amount) }) }}</td>
                                <td class="text-end"><v-btn size="small" variant="tonal" @click="router.delete(`/admin/settings/business-masters/sales-target/monthly/${item.id}`, { preserveScroll: true })">{{ MESSAGES.mastersUi.businessMasters.revoke }}</v-btn></td>
                            </tr>
                            <tr v-if="salesTargets.monthly.length === 0"><td colspan="3" class="text-medium-emphasis">{{ MESSAGES.mastersUi.businessMasters.noMonthlyTargets }}</td></tr>
                        </tbody>
                    </v-table>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- 雇用形態 -->
        <v-window-item value="employment">
            <SectionCard :title="MESSAGES.mastersUi.businessMasters.employmentTitle" :subtitle="MESSAGES.mastersUi.businessMasters.employmentSubtitle">
                <v-table class="bm-table">
                    <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.code }}</th><th>{{ MESSAGES.mastersUi.businessMasters.name }}</th><th>{{ MESSAGES.mastersUi.businessMasters.active }}</th></tr></thead>
                    <tbody>
                        <tr v-for="item in employmentTypes" :key="item.id">
                            <td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_active" color="primary" hide-details density="compact" :aria-label="fillMessage(MESSAGES.mastersUi.businessMasters.activeState, { name: item.name })" :disabled="isTogglePending('/admin/settings/business-masters/employment-types', item.id)" @update:model-value="toggleMaster(item, '/admin/settings/business-masters/employment-types', $event)" /></td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.addEmployment }}</p>
                    <v-form class="bm-form" @submit.prevent="employmentForm.post('/admin/settings/business-masters/employment-types', { preserveScroll: true, onSuccess: () => employmentForm.reset() })">
                        <v-text-field v-model="employmentForm.code" :label="MESSAGES.mastersUi.businessMasters.code" hide-details="auto" class="bm-field bm-field--s" :error-messages="employmentForm.errors.code" />
                        <v-text-field v-model="employmentForm.name" :label="MESSAGES.mastersUi.businessMasters.name" hide-details="auto" class="bm-field" :error-messages="employmentForm.errors.name" />
                        <v-text-field v-model.number="employmentForm.sort_order" :label="MESSAGES.mastersUi.businessMasters.sortOrder" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="employmentForm.processing">{{ MESSAGES.mastersUi.businessMasters.add }}</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- カルテ選択肢・資格 -->
        <v-window-item v-for="panel in ['karte', 'qualification']" :key="panel" :value="panel">
            <SectionCard v-for="kind in (panel === 'qualification' ? ['qualifications'] : ['acquisition-channels', 'visit-purposes']) as KarteKind[]" :key="kind" :title="kind === 'acquisition-channels' ? MESSAGES.customer.acquisitionChannel : kind === 'qualifications' ? MESSAGES.bookingResources.qualificationMaster : MESSAGES.customer.karteVisitPurposeMaster" class="mb-4">
                <v-table class="bm-table">
                    <thead><tr><th>{{ MESSAGES.mastersUi.businessMasters.code }}</th><th>{{ MESSAGES.mastersUi.businessMasters.name }}</th><th>{{ MESSAGES.mastersUi.businessMasters.active }}</th><th class="text-end">{{ MESSAGES.mastersUi.businessMasters.order }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(item, index) in karteLists(kind)" :key="item.id">
                            <td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_active" color="primary" hide-details density="compact" :aria-label="fillMessage(MESSAGES.mastersUi.businessMasters.activeState, { name: item.name })" :disabled="isTogglePending(`${karteBase}/${kind}`, item.id)" @update:model-value="toggleMaster(item, `${karteBase}/${kind}`, $event)" /></td>
                            <td class="text-end">
                                <div class="bm-actions">
                                    <v-btn size="small" variant="text" icon="mdi-arrow-up" :disabled="index === 0" :aria-label="MESSAGES.customer.karteMoveUp" @click="moveKarte(kind, item, -1)" />
                                    <v-btn size="small" variant="text" icon="mdi-arrow-down" :disabled="index === karteLists(kind).length - 1" :aria-label="MESSAGES.customer.karteMoveDown" @click="moveKarte(kind, item, 1)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">{{ MESSAGES.mastersUi.businessMasters.add }}</p>
                    <v-form class="bm-form" @submit.prevent="saveNewMaster(karteForms[kind] as typeof masterForm, `${karteBase}/${kind}`)">
                        <v-text-field v-model="karteForms[kind].code" :label="MESSAGES.mastersUi.businessMasters.code" hide-details="auto" class="bm-field bm-field--s" :error-messages="karteForms[kind].errors.code" />
                        <v-text-field v-model="karteForms[kind].name" :label="MESSAGES.mastersUi.businessMasters.name" hide-details="auto" class="bm-field" :error-messages="karteForms[kind].errors.name" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="karteForms[kind].processing">{{ MESSAGES.mastersUi.businessMasters.add }}</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>
    </v-window>
</template>

<style scoped>
/* 一覧 → 追加フォームの順にそろえる。入力欄は横に並べ、ボタンは入力欄と同じ高さ・並びに置く。 */
.bm-table th {
    white-space: nowrap;
}

.bm-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--ark-space-2);
}

.bm-rate {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--ark-space-3);
    padding: 4px 0;
    font-size: 0.875rem;
}

.bm-rate__value {
    min-width: 3em;
    font-size: 1rem;
    font-weight: 700;
}

.bm-dialog {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-3);
}

.bm-add {
    margin-top: var(--ark-space-5, 20px);
    padding-top: var(--ark-space-4);
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.1);
}

.bm-add--first {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
}

.bm-add__title {
    margin: 0 0 var(--ark-space-3);
    font-size: 0.875rem;
    font-weight: 700;
}

.bm-form {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--ark-space-3);
}

.bm-field {
    flex: 1 1 220px;
    max-width: 320px;
}

.bm-field--s {
    flex-basis: 170px;
    max-width: 220px;
}

.bm-field--xs {
    flex-basis: 110px;
    max-width: 140px;
}

/* density=comfortable の入力欄（48px）と同じ高さにして、上端をそろえる。 */
.bm-submit {
    height: 48px !important;
}

.bm-weekday {
    min-width: 48px;
    justify-content: center;
}

.bm-weekday--on {
    background: rgb(var(--v-theme-error));
    color: rgb(var(--v-theme-on-error));
    border-color: rgb(var(--v-theme-error));
}
</style>
