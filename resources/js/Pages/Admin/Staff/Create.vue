<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ColorField, PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

const roles = [
    { title: MESSAGES.mastersUi.staff.roles.staff, value: 'staff' },
    { title: MESSAGES.mastersUi.staff.roles.manager, value: 'manager' },
    { title: MESSAGES.mastersUi.staff.roles.admin, value: 'admin' },
] as const;

const form = useForm({
    name: '',
    email: '',
    display_name: '',
    role: 'staff',
    color: '#888888',
    is_bookable: true,
});

const submit = (): void => {
    form.post('/admin/staff');
};
</script>

<template>
    <Head :title="MESSAGES.mastersUi.staff.createHead" />

    <div class="ark-form-page">
        <PageHeader
            :title="MESSAGES.mastersUi.staff.createTitle"
            :subtitle="MESSAGES.mastersUi.staff.createSubtitle"
        />

        <v-form @submit.prevent="submit">
            <SectionCard :title="MESSAGES.mastersUi.staff.basicInformation">
                <v-text-field
                    v-model="form.name"
                    :label="MESSAGES.mastersUi.staff.name"
                    autocomplete="name"
                    hide-details="auto"
                    class="ark-field-name mb-4"
                    :error-messages="form.errors.name"
                    required
                />
                <v-text-field
                    v-model="form.display_name"
                    :label="MESSAGES.mastersUi.staff.screenDisplayName"
                    hide-details="auto"
                    class="ark-field-name mb-4"
                    :error-messages="form.errors.display_name"
                    required
                />
                <v-text-field
                    v-model="form.email"
                    :label="MESSAGES.mastersUi.staff.loginEmail"
                    type="email"
                    autocomplete="email"
                    hide-details="auto"
                    class="mb-4"
                    :error-messages="form.errors.email"
                    required
                />
                <ColorField v-model="form.color" :label="MESSAGES.mastersUi.staff.displayColor" />
                <div v-if="form.errors.color" class="text-error text-caption mt-1">
                    {{ form.errors.color }}
                </div>
            </SectionCard>

            <SectionCard :title="MESSAGES.mastersUi.staff.reservationSettings" class="mt-4">
                <v-select
                    v-model="form.role"
                    :label="MESSAGES.mastersUi.staff.role"
                    :items="roles"
                    hide-details="auto"
                    style="max-width: 220px"
                    :error-messages="form.errors.role"
                />
                <v-switch
                    v-model="form.is_bookable"
                    :label="MESSAGES.mastersUi.staff.acceptsBookings"
                    color="primary"
                    hide-details
                    class="mt-2"
                    :error-messages="form.errors.is_bookable"
                />
            </SectionCard>

            <div class="ark-form-page__actions">
                <v-spacer />
                <v-btn variant="text" href="/admin/staff">{{ MESSAGES.mastersUi.staff.cancel }}</v-btn>
                <v-btn type="submit" color="primary" size="large" :loading="form.processing">
                    {{ MESSAGES.mastersUi.staff.createAndSendEmail }}
                </v-btn>
            </div>
        </v-form>
    </div>
</template>

<style scoped>
.ark-form-page {
    max-width: 720px;
    margin-inline: auto;
}

.ark-form-page__actions {
    display: flex;
    align-items: center;
    gap: var(--ark-space-3);
    margin-top: var(--ark-space-5);
}
</style>
