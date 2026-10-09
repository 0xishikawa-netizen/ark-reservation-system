<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ColorField, PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES, confirmStaffUnbookableMessage } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

type StaffRole = 'staff' | 'manager' | 'admin';

interface StaffFormData {
    user_id: number;
    name: string;
    email: string;
    display_name: string;
    color: string;
    is_bookable: boolean;
    sort_order: number;
    role: StaffRole;
    is_active: boolean;
    service_ids: number[];
    qualification_ids: number[];
}

interface OptionRow { id: number; name: string; is_active: boolean }

interface RoleOption {
    title: string;
    value: StaffRole;
}

const props = defineProps<{
    staff: StaffFormData;
    roles: RoleOption[];
    services: OptionRow[];
    qualifications: OptionRow[];
}>();
const resourceLabels = MESSAGES.bookingResources;

const form = useForm({
    display_name: props.staff.display_name,
    color: props.staff.color,
    is_bookable: props.staff.is_bookable,
    sort_order: props.staff.sort_order,
    role: props.staff.role,
    is_active: props.staff.is_active,
    service_ids: [...props.staff.service_ids],
    qualification_ids: [...props.staff.qualification_ids],
});

const submit = (): void => {
    form.put(`/admin/staff/${props.staff.user_id}`);
};

const deactivate = (): void => {
    if (!window.confirm(confirmStaffUnbookableMessage(props.staff.display_name))) {
        return;
    }

    router.patch(`/admin/staff/${props.staff.user_id}/deactivate`);
};
</script>

<template>
    <Head :title="fillMessage(MESSAGES.mastersUi.staff.editHead, { name: staff.display_name })" />

    <div class="ark-form-page">
        <PageHeader :title="MESSAGES.mastersUi.staff.editTitle" :subtitle="staff.name" />

        <v-form @submit.prevent="submit">
            <SectionCard :title="MESSAGES.mastersUi.staff.basicInformation">
                <v-list density="compact" class="mb-2 bg-transparent">
                    <v-list-item :title="MESSAGES.mastersUi.staff.name" :subtitle="staff.name" />
                    <v-list-item :title="MESSAGES.mastersUi.staff.email" :subtitle="staff.email" />
                </v-list>
                <v-text-field
                    v-model="form.display_name"
                    :label="MESSAGES.mastersUi.staff.displayName"
                    maxlength="50"
                    hide-details="auto"
                    class="ark-field-name mb-4"
                    :error-messages="form.errors.display_name"
                    required
                />
                <ColorField v-model="form.color" :label="MESSAGES.mastersUi.staff.displayColor" />
                <div v-if="form.errors.color" class="text-error text-caption mt-1">
                    {{ form.errors.color }}
                </div>
            </SectionCard>

            <SectionCard :title="MESSAGES.mastersUi.staff.reservationSettings" class="mt-4">
                <div class="d-flex ga-4 flex-wrap">
                    <v-text-field
                        v-model.number="form.sort_order"
                        :label="MESSAGES.mastersUi.staff.sortOrder"
                        type="number"
                        min="0"
                        hide-details="auto"
                        style="max-width: 160px"
                        :error-messages="form.errors.sort_order"
                    />
                    <v-select
                        v-model="form.role"
                        :label="MESSAGES.mastersUi.staff.role"
                        :items="roles"
                        hide-details="auto"
                        style="max-width: 220px"
                        :error-messages="form.errors.role"
                        required
                    />
                </div>
                <v-switch
                    v-model="form.is_bookable"
                    :label="MESSAGES.mastersUi.staff.acceptsBookings"
                    color="primary"
                    hide-details
                    class="mt-2"
                    :error-messages="form.errors.is_bookable"
                />
            </SectionCard>

            <!-- Task 11-28: 実施できる施術と保有資格（資格が必要な施術は、資格を登録したスタッフだけが担当できる） -->
            <SectionCard :title="`${resourceLabels.staffServices}・${resourceLabels.staffQualifications}`" class="mt-4">
                <v-autocomplete
                    v-model="form.service_ids"
                    :label="resourceLabels.staffServices"
                    :items="services"
                    item-title="name"
                    item-value="id"
                    multiple
                    chips
                    closable-chips
                    :hint="resourceLabels.staffServicesHint"
                    persistent-hint
                    :error-messages="form.errors.service_ids"
                    class="mb-3"
                    data-testid="staff-services"
                />
                <v-autocomplete
                    v-model="form.qualification_ids"
                    :label="resourceLabels.staffQualifications"
                    :items="qualifications"
                    item-title="name"
                    item-value="id"
                    multiple
                    chips
                    closable-chips
                    :hint="resourceLabels.staffQualificationsHint"
                    persistent-hint
                    :error-messages="form.errors.qualification_ids"
                    data-testid="staff-qualifications"
                />
            </SectionCard>

            <SectionCard :title="MESSAGES.mastersUi.staff.login" :subtitle="MESSAGES.mastersUi.staff.loginSubtitle" class="mt-4">
                <v-switch
                    v-model="form.is_active"
                    :label="MESSAGES.mastersUi.staff.allowLogin"
                    color="primary"
                    hide-details="auto"
                    :error-messages="form.errors.is_active"
                />
                <v-alert
                    v-if="!form.is_active"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                >
                    {{ MESSAGES.staff.loginDisabledWarning }}
                </v-alert>
            </SectionCard>

            <v-alert type="info" variant="tonal" class="mt-4">
                {{ MESSAGES.mastersUi.staff.securityNotice }}
            </v-alert>

            <div class="ark-form-page__actions">
                <v-btn
                    color="error"
                    variant="outlined"
                    :disabled="!staff.is_bookable"
                    @click="deactivate"
                >
                    {{ MESSAGES.mastersUi.staff.disableBooking }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" href="/admin/staff">{{ MESSAGES.mastersUi.staff.cancel }}</v-btn>
                <v-btn type="submit" color="primary" size="large" :loading="form.processing">
                    {{ MESSAGES.mastersUi.staff.save }}
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
