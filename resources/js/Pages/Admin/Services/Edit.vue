<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface StaffOption {
    user_id: number;
    display_name: string;
    is_bookable: boolean;
}

interface ServiceFormData {
    id: number;
    name: string;
    duration_min: number;
    price: number;
    category: string | null;
    color: string;
    is_online_bookable: boolean;
    requires_staff: boolean;
    sort_order: number;
    staff_ids: number[];
}

const props = defineProps<{
    service: ServiceFormData;
    staff: StaffOption[];
}>();

const categorySuggestions = ['整体', 'トレーニング', 'コンディショニング'];

const form = useForm({
    name: props.service.name,
    duration_min: props.service.duration_min,
    price: props.service.price,
    category: props.service.category,
    color: props.service.color,
    is_online_bookable: props.service.is_online_bookable,
    requires_staff: props.service.requires_staff,
    sort_order: props.service.sort_order,
    staff_ids: [...props.service.staff_ids],
});

const submit = (): void => {
    form.put(`/admin/services/${props.service.id}`);
};
</script>

<template>
    <Head :title="`${service.name}を編集`" />

    <v-card max-width="760" title="サービス編集">
        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="サービス名"
                    :error-messages="form.errors.name"
                    maxlength="100"
                    required
                />
                <v-combobox
                    v-model="form.category"
                    label="カテゴリ"
                    :items="categorySuggestions"
                    :error-messages="form.errors.category"
                    clearable
                />
                <div class="d-flex ga-4 flex-wrap">
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
                <v-text-field
                    v-model="form.color"
                    label="表示色"
                    type="color"
                    :error-messages="form.errors.color"
                />
                <v-switch
                    v-model="form.is_online_bookable"
                    label="オンライン予約を受け付ける"
                    color="primary"
                    :error-messages="form.errors.is_online_bookable"
                />
                <v-switch
                    v-model="form.requires_staff"
                    label="施術スタッフを必要とする"
                    color="primary"
                    :error-messages="form.errors.requires_staff"
                />
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
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        更新
                    </v-btn>
                    <v-btn variant="text" href="/admin/services">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
