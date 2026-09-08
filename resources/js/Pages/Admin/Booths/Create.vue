<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const form = useForm({
    name: '',
    sort_order: 0,
    is_active: true,
});

const submit = (): void => {
    form.post('/admin/booths');
};
</script>

<template>
    <Head title="ブース作成" />

    <v-card max-width="640" title="ブース作成">
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
                        作成
                    </v-btn>
                    <v-btn variant="text" href="/admin/booths">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
