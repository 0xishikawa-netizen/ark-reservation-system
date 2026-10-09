<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';
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
    <Head :title="MESSAGES.mastersUi.booths.createTitle" />

    <v-card max-width="640" :title="MESSAGES.mastersUi.booths.createTitle">
        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    :label="MESSAGES.mastersUi.booths.name"
                    :error-messages="form.errors.name"
                    maxlength="50"
                    required
                />
                <v-text-field
                    v-model.number="form.sort_order"
                    :label="MESSAGES.mastersUi.booths.sortOrder"
                    type="number"
                    min="0"
                    :error-messages="form.errors.sort_order"
                />
                <v-switch
                    v-model="form.is_active"
                    :label="MESSAGES.mastersUi.booths.active"
                    color="primary"
                    :error-messages="form.errors.is_active"
                />
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        {{ MESSAGES.mastersUi.booths.create }}
                    </v-btn>
                    <v-btn variant="text" href="/admin/booths">{{ MESSAGES.mastersUi.booths.cancel }}</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
