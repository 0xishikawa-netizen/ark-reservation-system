<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

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
    <Head :title="fillMessage(MESSAGES.mastersUi.booths.editHead, { name: booth.name })" />

    <v-card max-width="640" :title="MESSAGES.mastersUi.booths.editTitle">
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
                        {{ MESSAGES.mastersUi.booths.update }}
                    </v-btn>
                    <v-btn variant="text" href="/admin/booths">{{ MESSAGES.mastersUi.booths.cancel }}</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
