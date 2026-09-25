<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ColorField, PageHeader, SectionCard } from '@/components/ark';

defineOptions({ layout: AdminLayout });

interface StaffOption {
    user_id: number;
    display_name: string;
    is_bookable: boolean;
}

interface MasterOption { id: number; code: string; name: string; is_active: boolean }

defineProps<{
    staff: StaffOption[];
    analysisCategories: MasterOption[];
    taxCategories: MasterOption[];
}>();

const categorySuggestions = ['整体', 'トレーニング', 'コンディショニング'];

const form = useForm({
    name: '',
    duration_min: 60,
    price: 0,
    category: null as string | null,
    analysis_category_id: null as number | null,
    tax_category_id: null as number | null,
    color: '#607d8b',
    is_online_bookable: true,
    requires_staff: true,
    sort_order: 0,
    staff_ids: [] as number[],
});

const submit = (): void => {
    form.post('/admin/services');
};
</script>

<template>
    <Head title="メニュー作成" />

    <div class="service-form-shell">
        <PageHeader
            title="メニュー作成"
            subtitle="予約時に表示するメニュー内容と受付条件を設定します。"
        />

        <v-form @submit.prevent="submit">
            <SectionCard>
                <section class="mb-6" aria-labelledby="service-create-basic">
                    <h2 id="service-create-basic" class="section-heading text-subtitle-1 mb-4">
                        基本情報
                    </h2>
                    <v-text-field
                        v-model="form.name"
                        class="mb-1"
                        label="メニュー名"
                        :error-messages="form.errors.name"
                        maxlength="100"
                        required
                    />
                    <v-combobox
                        v-model="form.category"
                        class="mb-1"
                        label="カテゴリ"
                        :items="categorySuggestions"
                        :error-messages="form.errors.category"
                        clearable
                    />
                    <div class="master-fields">
                        <v-select
                            v-model="form.analysis_category_id"
                            label="集計用メニュー分類"
                            :items="analysisCategories"
                            item-title="name"
                            item-value="id"
                            clearable
                            :error-messages="form.errors.analysis_category_id"
                        />
                        <v-select
                            v-model="form.tax_category_id"
                            label="税区分"
                            :items="taxCategories"
                            item-title="name"
                            item-value="id"
                            clearable
                            :error-messages="form.errors.tax_category_id"
                        />
                    </div>
                    <div class="mb-4">
                        <ColorField v-model="form.color" label="表示色" />
                        <div v-if="form.errors.color" class="text-error text-caption mt-1">
                            {{ form.errors.color }}
                        </div>
                    </div>
                </section>

                <v-divider class="mb-6" />

                <section aria-labelledby="service-create-booking">
                    <h2 id="service-create-booking" class="section-heading text-subtitle-1 mb-4">
                        予約設定
                    </h2>
                    <div class="number-fields mb-4">
                        <v-text-field
                            v-model.number="form.duration_min"
                            label="所要時間（分）"
                            type="number"
                            min="5"
                            max="600"
                            :error-messages="form.errors.duration_min"
                            required
                        />
                        <v-text-field
                            v-model.number="form.price"
                            label="価格（税込・円）"
                            type="number"
                            min="0"
                            :error-messages="form.errors.price"
                            required
                        />
                        <v-text-field
                            v-model.number="form.sort_order"
                            label="表示順"
                            type="number"
                            :error-messages="form.errors.sort_order"
                            required
                        />
                    </div>
                    <div class="switches mb-4">
                        <v-switch
                            v-model="form.is_online_bookable"
                            label="オンライン予約を受け付ける"
                            color="primary"
                            :error-messages="form.errors.is_online_bookable"
                            hide-details="auto"
                        />
                        <v-switch
                            v-model="form.requires_staff"
                            label="施術スタッフを必要とする"
                            color="primary"
                            :error-messages="form.errors.requires_staff"
                            hide-details="auto"
                        />
                    </div>
                    <v-autocomplete
                        v-model="form.staff_ids"
                        label="施術可能スタッフ"
                        :items="staff"
                        item-title="display_name"
                        item-value="user_id"
                        multiple
                        chips
                        closable-chips
                        :error-messages="form.errors.staff_ids"
                        :required="form.requires_staff"
                    >
                        <template #item="{ props: itemProps, item }">
                            <v-list-item
                                v-bind="itemProps"
                                :subtitle="item.raw.is_bookable ? undefined : '予約受付停止中'"
                            />
                        </template>
                    </v-autocomplete>
                </section>

                <div class="form-actions d-flex ga-3 flex-wrap">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        作成
                    </v-btn>
                    <v-btn variant="text" href="/admin/services">キャンセル</v-btn>
                </div>
            </SectionCard>
        </v-form>
    </div>
</template>

<style scoped>
.service-form-shell {
    max-width: 760px;
    margin-inline: auto;
}

.section-heading {
    color: rgb(var(--v-theme-primary));
    font-weight: 700;
}

.number-fields {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 200px));
    gap: var(--ark-space-4);
}

.master-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ark-space-4); }

.switches {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--ark-space-4);
}

.form-actions {
    position: sticky;
    bottom: 0;
    z-index: 2;
    margin: var(--ark-space-5) calc(var(--ark-space-4) * -1) calc(var(--ark-space-4) * -1);
    padding: var(--ark-space-4);
    border-top: 1px solid #D9DEE5;
    background: rgb(var(--v-theme-surface));
}

@media (max-width: 600px) {
    .number-fields,
    .switches,
    .master-fields {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
