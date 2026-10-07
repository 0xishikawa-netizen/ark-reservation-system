<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { DateField, EmptyValue, MoneyField, MonthField, PageHeader, SectionCard, StatusChip, TimeField } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });
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
const rateForm = useForm({ tax_category_id: null as number | null, rate_bps: 1000, effective_from: '', effective_to: null as string | null });
/** 税率は % で入力し、保存時に bp（1% = 100bp）へ直す。 */
const ratePercent = computed<number | null>({
    get: () => rateForm.rate_bps / 100,
    set: (value) => { rateForm.rate_bps = Math.round((value ?? 0) * 100); },
});
const paymentForm = useForm({ code: '', name: '', is_enabled: true, display_order: 0, external_provider: null as string | null });
/** 決済方法の変更（ダイアログ）用。追加フォームと入力を共有しない。 */
const paymentEditForm = useForm({ code: '', name: '', is_enabled: true, display_order: 0, external_provider: null as string | null });
const calendarForm = useForm({ business_date: '', status: 'closed' as 'closed' | 'special_hours' | 'open', opens_at: null as string | null, closes_at: null as string | null, note: null as string | null });
const closedWeekdaysForm = useForm({ weekdays: [...(props.closedWeekdays ?? [])] });
const weekdayOptions = [
    { value: 1, label: '月' }, { value: 2, label: '火' }, { value: 3, label: '水' }, { value: 4, label: '木' },
    { value: 5, label: '金' }, { value: 6, label: '土' }, { value: 7, label: '日' },
];
const calendarStatusItems = [
    { title: '休業（臨時休業）', value: 'closed' },
    { title: '営業時間を変更', value: 'special_hours' },
    { title: '営業（定休日だけど営業）', value: 'open' },
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
const displayDate = (value: string | null): string => (value ? value.slice(0, 10).replaceAll('-', '/') : '');
const ratePercentLabel = (bps: number): string => `${(bps / 100).toFixed(bps % 100 === 0 ? 0 : 2)}%`;
// 税率の追加・変更はダイアログで行う（一覧の下のフォームだと、どの行を編集中か分かりにくいため）。
const rateDialogOpen = ref(false);
const openRateDialog = (category: TaxCategory, rate: TaxRate | null = null): void => {
    rateForm.clearErrors();
    editingRateId.value = rate?.id ?? null;
    rateForm.tax_category_id = category.id;
    rateForm.rate_bps = rate?.rate_bps ?? 1000;
    rateForm.effective_from = rate ? rate.effective_from.slice(0, 10) : '';
    rateForm.effective_to = rate?.effective_to ? rate.effective_to.slice(0, 10) : null;
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
    'acquisition-channels': useForm({ code: '', name: '', is_active: true, sort_order: 100 }),
    'visit-purposes': useForm({ code: '', name: '', is_active: true, sort_order: 100 }),
    qualifications: useForm({ code: '', name: '', is_active: true, sort_order: 100 }),
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
const money = (value: number): string => new Intl.NumberFormat('ja-JP').format(value);
</script>

<template>
    <Head title="業務マスタ" />
    <PageHeader title="業務マスタ" :subtitle="`集計の前提となる分類・税・決済・営業日・目標を管理します（${business.timezone}）。`" />
    <v-tabs v-model="tab" class="mb-4" show-arrows color="primary">
        <v-tab value="analysis">メニュー分類</v-tab>
        <v-tab value="tax">税</v-tab>
        <v-tab value="payment">決済方法</v-tab>
        <v-tab value="calendar">店舗カレンダー</v-tab>
        <v-tab value="target">売上目標</v-tab>
        <v-tab value="employment">雇用形態</v-tab>
        <v-tab value="karte">{{ MESSAGES.customer.karteMasterTab }}</v-tab>
        <v-tab value="qualification">{{ MESSAGES.bookingResources.qualificationMasterTab }}</v-tab>
    </v-tabs>

    <v-window v-model="tab">
        <!-- メニュー分類 -->
        <v-window-item value="analysis">
            <SectionCard title="分析カテゴリ" subtitle="メニューを集計（M・T・A など）でまとめるための分類です。">
                <v-table class="bm-table">
                    <thead><tr><th>コード</th><th>名称</th><th>有効</th></tr></thead>
                    <tbody>
                        <tr v-for="item in analysisCategories" :key="item.id">
                            <td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_active" color="primary" hide-details density="compact" :aria-label="`${item.name}の有効状態`" :disabled="isTogglePending('/admin/settings/business-masters/analysis-categories', item.id)" @update:model-value="toggleMaster(item, '/admin/settings/business-masters/analysis-categories', $event)" /></td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">分類を追加</p>
                    <v-form class="bm-form" @submit.prevent="saveNewMaster(masterForm, '/admin/settings/business-masters/analysis-categories')">
                        <v-text-field v-model="masterForm.code" label="コード" hide-details="auto" class="bm-field bm-field--s" :error-messages="masterForm.errors.code" />
                        <v-text-field v-model="masterForm.name" label="名称" hide-details="auto" class="bm-field" :error-messages="masterForm.errors.name" />
                        <v-text-field v-model.number="masterForm.sort_order" label="表示順" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="masterForm.processing">追加</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- 税 -->
        <v-window-item value="tax">
            <SectionCard title="税区分と税率" subtitle="税区分ごとに、いつから何%かを登録します。会計の税額はこの税率で計算します。">
                <v-table class="bm-table">
                    <thead><tr><th>税区分</th><th>税率と適用期間</th><th>有効</th><th class="text-end">操作</th></tr></thead>
                    <tbody>
                        <tr v-for="category in taxCategories" :key="category.id">
                            <td><strong>{{ category.name }}</strong><div class="text-caption text-medium-emphasis">{{ category.code }}</div></td>
                            <td>
                                <div v-for="rate in category.rates" :key="rate.id" class="bm-rate">
                                    <span class="bm-rate__value">{{ ratePercentLabel(rate.rate_bps) }}</span>
                                    <span class="text-medium-emphasis">{{ displayDate(rate.effective_from) }} 〜 {{ rate.effective_to ? displayDate(rate.effective_to) : '期限なし' }}</span>
                                    <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" @click="openRateDialog(category, rate)">編集</v-btn>
                                </div>
                                <span v-if="category.rates.length === 0" class="text-error text-body-2">{{ MESSAGES.settings.taxRateMissing }}</span>
                            </td>
                            <td><v-switch :model-value="category.is_active" color="primary" hide-details density="compact" :aria-label="`${category.name}の有効状態`" :disabled="isTogglePending('/admin/settings/business-masters/tax-categories', category.id)" @update:model-value="toggleMaster(category, '/admin/settings/business-masters/tax-categories', $event)" /></td>
                            <td class="text-end"><v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="openRateDialog(category)">税率を追加</v-btn></td>
                        </tr>
                    </tbody>
                </v-table>
                <!-- 税率の追加・変更ダイアログ -->
                <v-dialog v-model="rateDialogOpen" max-width="440" @update:model-value="(open) => { if (!open) closeRateDialog(); }">
                    <v-card>
                        <v-card-title class="text-subtitle-1 font-weight-bold">{{ editingRateId === null ? '税率を追加' : '税率を変更' }}（{{ rateDialogCategoryName }}）</v-card-title>
                        <v-card-text class="bm-dialog">
                            <v-select v-model="rateForm.tax_category_id" :items="taxCategories" item-title="name" item-value="id" label="税区分" disabled hide-details="auto" :error-messages="rateForm.errors.tax_category_id" />
                            <v-text-field v-model.number="ratePercent" label="税率" suffix="%" type="number" step="0.01" min="0" hide-details="auto" :error-messages="rateForm.errors.rate_bps" />
                            <DateField v-model="rateForm.effective_from" label="開始日" :clearable="false" block hide-details="auto" :error-messages="rateForm.errors.effective_from" />
                            <DateField :model-value="rateForm.effective_to ?? ''" label="終了日（任意・当日含まず）" block hide-details="auto" :error-messages="rateForm.errors.effective_to" @update:model-value="rateForm.effective_to = $event || null" />
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn variant="text" :disabled="rateForm.processing" @click="closeRateDialog">キャンセル</v-btn>
                            <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="rateForm.processing" @click="saveRate">保存</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
                <div class="bm-add">
                    <p class="bm-add__title">税区分を追加</p>
                    <v-form class="bm-form" @submit.prevent="taxCategoryForm.post('/admin/settings/business-masters/tax-categories', { preserveScroll: true, onSuccess: () => taxCategoryForm.reset() })">
                        <v-text-field v-model="taxCategoryForm.code" label="コード" hide-details="auto" class="bm-field bm-field--s" :error-messages="taxCategoryForm.errors.code" />
                        <v-text-field v-model="taxCategoryForm.name" label="名称" hide-details="auto" class="bm-field" :error-messages="taxCategoryForm.errors.name" />
                        <v-text-field v-model.number="taxCategoryForm.sort_order" label="表示順" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="taxCategoryForm.processing">追加</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- 決済方法 -->
        <v-window-item value="payment">
            <SectionCard title="決済方法" subtitle="会計の支払方法と、月計・Excel の列に使います。">
                <v-table class="bm-table">
                    <thead><tr><th>表示順</th><th>コード</th><th>名称</th><th>有効</th><th class="text-end">操作</th></tr></thead>
                    <tbody>
                        <tr v-for="item in paymentMethods" :key="item.id">
                            <td>{{ item.display_order }}</td><td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_enabled" color="primary" hide-details density="compact" :aria-label="`${item.name}の有効状態`" :disabled="isTogglePending(paymentPath, item.id)" @update:model-value="togglePayment(item, $event)" /></td>
                            <td class="text-end"><v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" @click="editPayment(item)">編集</v-btn></td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">決済方法を追加</p>
                    <v-form class="bm-form" @submit.prevent="addPayment">
                        <v-text-field v-model="paymentForm.code" label="コード" hide-details="auto" class="bm-field bm-field--s" :error-messages="paymentForm.errors.code" />
                        <v-text-field v-model="paymentForm.name" label="名称" hide-details="auto" class="bm-field" :error-messages="paymentForm.errors.name" />
                        <v-text-field v-model.number="paymentForm.display_order" label="表示順" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-text-field v-model="paymentForm.external_provider" label="外部連携（任意）" hide-details="auto" class="bm-field bm-field--s" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="paymentForm.processing">追加</v-btn>
                    </v-form>
                </div>
                <!-- 決済方法の変更ダイアログ -->
                <v-dialog v-model="paymentDialogOpen" max-width="440" @update:model-value="(open) => { if (!open) closePaymentDialog(); }">
                    <v-card>
                        <v-card-title class="text-subtitle-1 font-weight-bold">決済方法を変更</v-card-title>
                        <v-card-text class="bm-dialog">
                            <v-text-field v-model="paymentEditForm.code" label="コード" hide-details="auto" :error-messages="paymentEditForm.errors.code" />
                            <v-text-field v-model="paymentEditForm.name" label="名称" hide-details="auto" :error-messages="paymentEditForm.errors.name" />
                            <v-text-field v-model.number="paymentEditForm.display_order" label="表示順" type="number" hide-details="auto" :error-messages="paymentEditForm.errors.display_order" />
                            <v-text-field v-model="paymentEditForm.external_provider" label="外部連携（任意）" hide-details="auto" :error-messages="paymentEditForm.errors.external_provider" />
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn variant="text" :disabled="paymentEditForm.processing" @click="closePaymentDialog">キャンセル</v-btn>
                            <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="paymentEditForm.processing" @click="updatePayment">保存</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
            </SectionCard>
        </v-window-item>

        <!-- 店舗カレンダー -->
        <v-window-item value="calendar">
            <SectionCard title="定休日（毎週）" :subtitle="`通常の営業時間は ${business.default_opens_at}〜${business.default_closes_at} です。毎週休む曜日を選んでください。`" class="mb-4">
                <v-form class="bm-form" @submit.prevent="closedWeekdaysForm.put('/admin/settings/business-masters/calendar/closed-weekdays', { preserveScroll: true })">
                    <v-chip-group v-model="closedWeekdaysForm.weekdays" multiple column selected-class="bm-weekday--on" aria-label="定休日の曜日">
                        <v-chip v-for="day in weekdayOptions" :key="day.value" :value="day.value" filter variant="outlined" class="bm-weekday">{{ day.label }}</v-chip>
                    </v-chip-group>
                    <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" class="bm-submit" :loading="closedWeekdaysForm.processing">保存</v-btn>
                </v-form>
                <p class="text-body-2 text-medium-emphasis mt-2">{{ closedWeekdaysForm.weekdays.length === 0 ? '定休日なし（毎日営業）' : `毎週 ${weekdayOptions.filter((d) => closedWeekdaysForm.weekdays.includes(d.value)).map((d) => d.label).join('・')} 曜日は休業` }}</p>
            </SectionCard>

            <SectionCard title="特定の日の休業・営業" subtitle="臨時休業・営業時間の変更・定休日の営業など、いつもと違う日だけ登録します。">
                <v-form class="bm-form" @submit.prevent="saveCalendarDay">
                    <DateField v-model="calendarForm.business_date" label="日付" :clearable="false" />
                    <v-select v-model="calendarForm.status" label="区分" :items="calendarStatusItems" hide-details="auto" class="bm-field" />
                    <TimeField v-if="calendarForm.status === 'special_hours'" :model-value="calendarForm.opens_at ?? ''" label="開店" @update:model-value="calendarForm.opens_at = $event || null" />
                    <TimeField v-if="calendarForm.status === 'special_hours'" :model-value="calendarForm.closes_at ?? ''" label="閉店" @update:model-value="calendarForm.closes_at = $event || null" />
                    <v-text-field v-model="calendarForm.note" label="備考（任意）" hide-details="auto" class="bm-field" />
                    <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" class="bm-submit" :loading="calendarForm.processing">保存</v-btn>
                </v-form>
                <p v-if="calendarForm.errors.closes_at || calendarForm.errors.business_date" class="text-error text-body-2 mt-2">{{ calendarForm.errors.closes_at || calendarForm.errors.business_date }}</p>
                <v-table class="bm-table mt-4">
                    <thead><tr><th>日付</th><th>区分</th><th>営業時間</th><th>備考</th><th class="text-end">操作</th></tr></thead>
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
                            <td class="text-end"><v-btn size="small" variant="tonal" @click="router.delete(`/admin/settings/business-masters/calendar/${day.id}`, { preserveScroll: true })">登録を取り消す</v-btn></td>
                        </tr>
                        <tr v-if="calendarDays.length === 0"><td colspan="5" class="text-medium-emphasis">登録はありません。</td></tr>
                    </tbody>
                </v-table>
            </SectionCard>
        </v-window-item>

        <!-- 売上目標 -->
        <v-window-item value="target">
            <SectionCard title="売上目標" subtitle="月計の達成率に使います。月別の目標が無い月はデフォルトの目標を使います。">
                <div class="bm-add bm-add--first">
                    <p class="bm-add__title">デフォルトの月間目標</p>
                    <v-form class="bm-form" @submit.prevent="defaultTargetForm.put('/admin/settings/business-masters/sales-target/default', { preserveScroll: true })">
                        <MoneyField v-model="defaultTargetForm.target_amount" label="月間目標" hide-details="auto" class="bm-field" :error-messages="defaultTargetForm.errors.target_amount" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" class="bm-submit" :loading="defaultTargetForm.processing">保存</v-btn>
                    </v-form>
                </div>
                <div class="bm-add">
                    <p class="bm-add__title">月別の目標</p>
                    <v-form class="bm-form" @submit.prevent="monthlyTargetForm.put('/admin/settings/business-masters/sales-target/monthly', { preserveScroll: true, onSuccess: () => monthlyTargetForm.reset() })">
                        <MonthField v-model="monthlyTargetForm.target_month" :label="MESSAGES.calendar.targetMonth" />
                        <MoneyField v-model="monthlyTargetForm.target_amount" label="目標" hide-details="auto" class="bm-field" :error-messages="monthlyTargetForm.errors.target_amount" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="monthlyTargetForm.processing">設定</v-btn>
                    </v-form>
                    <v-table class="bm-table mt-4">
                        <thead><tr><th>対象月</th><th class="text-end">目標</th><th class="text-end">操作</th></tr></thead>
                        <tbody>
                            <tr v-for="item in salesTargets.monthly" :key="item.id">
                                <td>{{ item.target_month }}</td>
                                <td class="text-end">{{ money(item.target_amount) }}円</td>
                                <td class="text-end"><v-btn size="small" variant="tonal" @click="router.delete(`/admin/settings/business-masters/sales-target/monthly/${item.id}`, { preserveScroll: true })">取り消す</v-btn></td>
                            </tr>
                            <tr v-if="salesTargets.monthly.length === 0"><td colspan="3" class="text-medium-emphasis">月別の目標はありません。</td></tr>
                        </tbody>
                    </v-table>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- 雇用形態 -->
        <v-window-item value="employment">
            <SectionCard title="雇用形態" subtitle="稼働率（社員・アルバイト）の集計に使います。">
                <v-table class="bm-table">
                    <thead><tr><th>コード</th><th>名称</th><th>有効</th></tr></thead>
                    <tbody>
                        <tr v-for="item in employmentTypes" :key="item.id">
                            <td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_active" color="primary" hide-details density="compact" :aria-label="`${item.name}の有効状態`" :disabled="isTogglePending('/admin/settings/business-masters/employment-types', item.id)" @update:model-value="toggleMaster(item, '/admin/settings/business-masters/employment-types', $event)" /></td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="bm-add">
                    <p class="bm-add__title">雇用形態を追加</p>
                    <v-form class="bm-form" @submit.prevent="employmentForm.post('/admin/settings/business-masters/employment-types', { preserveScroll: true, onSuccess: () => employmentForm.reset() })">
                        <v-text-field v-model="employmentForm.code" label="コード" hide-details="auto" class="bm-field bm-field--s" :error-messages="employmentForm.errors.code" />
                        <v-text-field v-model="employmentForm.name" label="名称" hide-details="auto" class="bm-field" :error-messages="employmentForm.errors.name" />
                        <v-text-field v-model.number="employmentForm.sort_order" label="表示順" type="number" hide-details="auto" class="bm-field bm-field--xs" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="employmentForm.processing">追加</v-btn>
                    </v-form>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- カルテ選択肢・資格 -->
        <v-window-item v-for="panel in ['karte', 'qualification']" :key="panel" :value="panel">
            <SectionCard v-for="kind in (panel === 'qualification' ? ['qualifications'] : ['acquisition-channels', 'visit-purposes']) as KarteKind[]" :key="kind" :title="kind === 'acquisition-channels' ? MESSAGES.customer.acquisitionChannel : kind === 'qualifications' ? MESSAGES.bookingResources.qualificationMaster : MESSAGES.customer.karteVisitPurposeMaster" class="mb-4">
                <v-table class="bm-table">
                    <thead><tr><th>コード</th><th>名称</th><th>有効</th><th class="text-end">並び順</th></tr></thead>
                    <tbody>
                        <tr v-for="(item, index) in karteLists(kind)" :key="item.id">
                            <td>{{ item.code }}</td><td>{{ item.name }}</td>
                            <td><v-switch :model-value="item.is_active" color="primary" hide-details density="compact" :aria-label="`${item.name}の有効状態`" :disabled="isTogglePending(`${karteBase}/${kind}`, item.id)" @update:model-value="toggleMaster(item, `${karteBase}/${kind}`, $event)" /></td>
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
                    <p class="bm-add__title">追加</p>
                    <v-form class="bm-form" @submit.prevent="saveNewMaster(karteForms[kind] as typeof masterForm, `${karteBase}/${kind}`)">
                        <v-text-field v-model="karteForms[kind].code" label="コード" hide-details="auto" class="bm-field bm-field--s" :error-messages="karteForms[kind].errors.code" />
                        <v-text-field v-model="karteForms[kind].name" label="名称" hide-details="auto" class="bm-field" :error-messages="karteForms[kind].errors.name" />
                        <v-btn type="submit" color="primary" prepend-icon="mdi-plus" class="bm-submit" :loading="karteForms[kind].processing">追加</v-btn>
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
