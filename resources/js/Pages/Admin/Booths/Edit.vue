<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface BoothFormData {
    id: number;
    name: string;
    sort_order: number;
    is_active: boolean;
}

const props = defineProps<{
    booth: BoothFormData;
}>();

const form = useForm({
    name: props.booth.name,
    sort_order: props.booth.sort_order,
    is_active: props.booth.is_active,
});

const submit = (): void => {
    form.put(`/admin/booths/${props.booth.id}`);
};
</script>

<template>
    <Head :title="`${booth.name}を編集`" />

    <v-card max-width="640" title="ブース編集">
        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="ブース名"
                    :error-messages="form.errors.name"
                    maxlength="50"
                    required
                />
                <v-text-field
                    v-model.number="form.sort_order"
                    label="表示順"
                    type="number"
                    min="0"
                    :error-messages="form.errors.sort_order"
                />
                <v-switch
                    v-model="form.is_active"
                    label="有効"
                    color="primary"
                    :error-messages="form.errors.is_active"
                />
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        更新
                    </v-btn>
                    <v-btn variant="text" href="/admin/booths">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
