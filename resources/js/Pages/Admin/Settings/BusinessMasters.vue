<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });
interface Master { id: number; code: string; name: string; is_active: boolean; sort_order: number }
interface TaxRate { id: number; tax_category_id: number; rate_bps: number; effective_from: string; effective_to: string | null }
interface TaxCategory extends Master { rates: TaxRate[] }
interface PaymentMethod { id: number; code: string; name: string; is_enabled: boolean; display_order: number; external_provider: string | null }
interface CalendarDay { id: number; business_date: string; status: 'closed' | 'special_hours'; opens_at: string | null; closes_at: string | null; note: string | null }
interface MonthlyTarget { id: number; target_month: string; target_amount: number }
const props = defineProps<{
    analysisCategories: Master[]; taxCategories: TaxCategory[]; paymentMethods: PaymentMethod[];
    calendarDays: CalendarDay[]; salesTargets: { default_amount: number; monthly: MonthlyTarget[] };
    employmentTypes: Master[]; business: { timezone: string; default_opens_at: string; default_closes_at: string };
}>();
const tab = ref('analysis');
const editingRateId = ref<number | null>(null);
const editingPaymentId = ref<number | null>(null);
const masterForm = useForm({ code: '', name: '', is_active: true, sort_order: 0 });
const taxCategoryForm = useForm({ code: '', name: '', is_active: true, sort_order: 0 });
const rateForm = useForm({ tax_category_id: null as number | null, rate_bps: 1000, effective_from: '', effective_to: null as string | null });
const paymentForm = useForm({ code: '', name: '', is_enabled: true, display_order: 0, external_provider: null as string | null });
const calendarForm = useForm({ business_date: '', status: 'closed' as 'closed' | 'special_hours', opens_at: null as string | null, closes_at: null as string | null, note: null as string | null });
const defaultTargetForm = useForm({ target_amount: props.salesTargets.default_amount });
const monthlyTargetForm = useForm({ target_month: '', target_amount: 0 });
const employmentForm = useForm({ code: '', name: '', is_active: true, sort_order: 0 });
const saveNewMaster = (form: typeof masterForm, path: string): void => form.post(path, { preserveScroll: true, onSuccess: () => form.reset() });
const toggleMaster = (item: Master, path: string): void => router.put(`${path}/${item.id}`, { ...item, is_active: !item.is_active }, { preserveScroll: true });
const togglePayment = (item: PaymentMethod): void => router.put(`/admin/settings/business-masters/payment-methods/${item.id}`, { ...item, is_enabled: !item.is_enabled }, { preserveScroll: true });
const editRate = (rate: TaxRate): void => {
    editingRateId.value = rate.id;
    rateForm.tax_category_id = rate.tax_category_id;
    rateForm.rate_bps = rate.rate_bps;
    rateForm.effective_from = rate.effective_from;
    rateForm.effective_to = rate.effective_to;
};
const saveRate = (): void => {
    const options = { preserveScroll: true, onSuccess: (): void => { editingRateId.value = null; rateForm.reset(); } };
    if (editingRateId.value === null) rateForm.post('/admin/settings/business-masters/tax-rates', options);
    else rateForm.put(`/admin/settings/business-masters/tax-rates/${editingRateId.value}`, options);
};
const editPayment = (item: PaymentMethod): void => {
    editingPaymentId.value = item.id;
    paymentForm.code = item.code;
    paymentForm.name = item.name;
    paymentForm.is_enabled = item.is_enabled;
    paymentForm.display_order = item.display_order;
    paymentForm.external_provider = item.external_provider;
};
const savePayment = (): void => {
    const options = { preserveScroll: true, onSuccess: (): void => { editingPaymentId.value = null; paymentForm.reset(); } };
    if (editingPaymentId.value === null) paymentForm.post('/admin/settings/business-masters/payment-methods', options);
    else paymentForm.put(`/admin/settings/business-masters/payment-methods/${editingPaymentId.value}`, options);
};
const money = (value: number): string => new Intl.NumberFormat('ja-JP').format(value);
</script>

<template>
    <Head title="業務マスタ" />
    <PageHeader title="業務マスタ" :subtitle="`集計の前提となる分類・税・決済・営業日・目標を管理します（${business.timezone}）。`" />
    <v-tabs v-model="tab" class="mb-4" show-arrows>
        <v-tab value="analysis">メニュー分類</v-tab><v-tab value="tax">税</v-tab><v-tab value="payment">決済方法</v-tab>
        <v-tab value="calendar">店舗カレンダー</v-tab><v-tab value="target">売上目標</v-tab><v-tab value="employment">雇用形態</v-tab>
    </v-tabs>
    <v-window v-model="tab">
        <v-window-item value="analysis"><SectionCard title="分析カテゴリ">
            <v-table><thead><tr><th>コード</th><th>名称</th><th>状態</th><th></th></tr></thead><tbody><tr v-for="item in analysisCategories" :key="item.id"><td>{{ item.code }}</td><td>{{ item.name }}</td><td><StatusChip :status="item.is_active ? 'active' : 'canceled'" :label="item.is_active ? '有効' : '無効'" /></td><td><v-btn size="small" variant="text" @click="toggleMaster(item, '/admin/settings/business-masters/analysis-categories')">{{ item.is_active ? '無効化' : '有効化' }}</v-btn></td></tr></tbody></v-table>
            <v-form class="inline-form mt-5" @submit.prevent="saveNewMaster(masterForm, '/admin/settings/business-masters/analysis-categories')"><v-text-field v-model="masterForm.code" label="コード" /><v-text-field v-model="masterForm.name" label="名称" /><v-text-field v-model.number="masterForm.sort_order" label="表示順" type="number" /><v-btn type="submit" color="primary">追加</v-btn></v-form>
        </SectionCard></v-window-item>
        <v-window-item value="tax"><SectionCard title="税区分と適用期間">
            <div v-for="category in taxCategories" :key="category.id" class="mb-5"><div class="d-flex align-center ga-3"><strong>{{ category.name }} ({{ category.code }})</strong><StatusChip :status="category.is_active ? 'active' : 'canceled'" :label="category.is_active ? '有効' : '無効'" /><v-btn size="small" variant="text" @click="toggleMaster(category, '/admin/settings/business-masters/tax-categories')">{{ category.is_active ? '無効化' : '有効化' }}</v-btn></div><div v-for="rate in category.rates" :key="rate.id" class="text-body-2 ml-4">{{ rate.effective_from }} 〜 {{ rate.effective_to || '期限なし' }}: {{ (rate.rate_bps / 100).toFixed(2) }}% <v-btn size="x-small" variant="text" @click="editRate(rate)">編集</v-btn></div></div>
            <v-form class="inline-form" @submit.prevent="taxCategoryForm.post('/admin/settings/business-masters/tax-categories', { preserveScroll: true, onSuccess: () => taxCategoryForm.reset() })"><v-text-field v-model="taxCategoryForm.code" label="税区分コード" /><v-text-field v-model="taxCategoryForm.name" label="名称" /><v-text-field v-model.number="taxCategoryForm.sort_order" label="表示順" type="number" /><v-btn type="submit" color="primary">税区分追加</v-btn></v-form>
            <v-divider class="my-5" /><v-form class="inline-form" @submit.prevent="saveRate"><v-select v-model="rateForm.tax_category_id" :items="taxCategories" item-title="name" item-value="id" label="税区分" /><v-text-field v-model.number="rateForm.rate_bps" label="税率(bp)" type="number" /><v-text-field v-model="rateForm.effective_from" label="開始日" type="date" /><v-text-field v-model="rateForm.effective_to" label="終了日（当日含まず）" type="date" clearable /><v-btn type="submit" color="primary">{{ editingRateId === null ? '税率追加' : '税率更新' }}</v-btn></v-form>
        </SectionCard></v-window-item>
        <v-window-item value="payment"><SectionCard title="決済方法">
            <v-table><thead><tr><th>表示順</th><th>コード</th><th>名称</th><th>状態</th><th></th></tr></thead><tbody><tr v-for="item in paymentMethods" :key="item.id"><td>{{ item.display_order }}</td><td>{{ item.code }}</td><td>{{ item.name }}</td><td>{{ item.is_enabled ? '有効' : '無効' }}</td><td><v-btn size="small" variant="text" @click="editPayment(item)">編集</v-btn><v-btn size="small" variant="text" @click="togglePayment(item)">{{ item.is_enabled ? '無効化' : '有効化' }}</v-btn></td></tr></tbody></v-table>
            <v-form class="inline-form mt-5" @submit.prevent="savePayment"><v-text-field v-model="paymentForm.code" label="コード" /><v-text-field v-model="paymentForm.name" label="名称" /><v-text-field v-model.number="paymentForm.display_order" label="表示順" type="number" /><v-text-field v-model="paymentForm.external_provider" label="外部provider（任意）" /><v-btn type="submit" color="primary">{{ editingPaymentId === null ? '追加' : '更新' }}</v-btn></v-form>
        </SectionCard></v-window-item>
        <v-window-item value="calendar"><SectionCard title="店舗カレンダー">
            <p class="text-body-2 mb-4">通常営業 {{ business.default_opens_at }}〜{{ business.default_closes_at }}。例外日のみ登録します。</p>
            <v-form class="inline-form" @submit.prevent="calendarForm.put('/admin/settings/business-masters/calendar', { preserveScroll: true, onSuccess: () => calendarForm.reset() })"><v-text-field v-model="calendarForm.business_date" label="営業日" type="date" /><v-select v-model="calendarForm.status" label="状態" :items="[{title:'休業',value:'closed'},{title:'特別営業時間',value:'special_hours'}]" /><v-text-field v-if="calendarForm.status === 'special_hours'" v-model="calendarForm.opens_at" label="開店" type="time" /><v-text-field v-if="calendarForm.status === 'special_hours'" v-model="calendarForm.closes_at" label="閉店" type="time" /><v-text-field v-model="calendarForm.note" label="備考" /><v-btn type="submit" color="primary">保存</v-btn></v-form>
            <v-table class="mt-5"><thead><tr><th>日付</th><th>状態</th><th>時間</th><th>備考</th><th></th></tr></thead><tbody><tr v-for="day in calendarDays" :key="day.id"><td>{{ day.business_date }}</td><td>{{ day.status === 'closed' ? '休業' : '特別営業' }}</td><td>{{ day.opens_at && day.closes_at ? `${day.opens_at}〜${day.closes_at}` : '—' }}</td><td>{{ day.note || '—' }}</td><td><v-btn size="small" variant="text" @click="router.delete(`/admin/settings/business-masters/calendar/${day.id}`, { preserveScroll: true })">通常営業に戻す</v-btn></td></tr></tbody></v-table>
        </SectionCard></v-window-item>
        <v-window-item value="target"><SectionCard title="売上目標">
            <v-form class="inline-form" @submit.prevent="defaultTargetForm.put('/admin/settings/business-masters/sales-target/default', { preserveScroll: true })"><v-text-field v-model.number="defaultTargetForm.target_amount" label="デフォルト月間目標（円）" type="number" min="0" /><v-btn type="submit" color="primary">保存</v-btn></v-form>
            <v-form class="inline-form mt-5" @submit.prevent="monthlyTargetForm.put('/admin/settings/business-masters/sales-target/monthly', { preserveScroll: true, onSuccess: () => monthlyTargetForm.reset() })"><v-text-field v-model="monthlyTargetForm.target_month" label="対象月" type="month" /><v-text-field v-model.number="monthlyTargetForm.target_amount" label="月別目標（円）" type="number" min="0" /><v-btn type="submit" color="primary">月別設定</v-btn></v-form>
            <v-list><v-list-item v-for="item in salesTargets.monthly" :key="item.id" :title="item.target_month" :subtitle="`${money(item.target_amount)}円`"><template #append><v-btn size="small" variant="text" @click="router.delete(`/admin/settings/business-masters/sales-target/monthly/${item.id}`, { preserveScroll: true })">削除</v-btn></template></v-list-item></v-list>
        </SectionCard></v-window-item>
        <v-window-item value="employment"><SectionCard title="雇用形態">
            <v-table><thead><tr><th>コード</th><th>名称</th><th>状態</th><th></th></tr></thead><tbody><tr v-for="item in employmentTypes" :key="item.id"><td>{{ item.code }}</td><td>{{ item.name }}</td><td>{{ item.is_active ? '有効' : '無効' }}</td><td><v-btn size="small" variant="text" @click="toggleMaster(item, '/admin/settings/business-masters/employment-types')">{{ item.is_active ? '無効化' : '有効化' }}</v-btn></td></tr></tbody></v-table>
            <v-form class="inline-form mt-5" @submit.prevent="employmentForm.post('/admin/settings/business-masters/employment-types', { preserveScroll: true, onSuccess: () => employmentForm.reset() })"><v-text-field v-model="employmentForm.code" label="コード" /><v-text-field v-model="employmentForm.name" label="名称" /><v-text-field v-model.number="employmentForm.sort_order" label="表示順" type="number" /><v-btn type="submit" color="primary">追加</v-btn></v-form>
        </SectionCard></v-window-item>
    </v-window>
</template>
<style scoped>.inline-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:start}</style>
