<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ColorField, PageHeader, SectionCard, MoneyField } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import ServiceResourceFields from '@/components/admin/ServiceResourceFields.vue';
import { fillMessage } from '@/utils/message';

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
    analysis_category_id: number | null;
    tax_category_id: number | null;
    color: string;
    is_online_bookable: boolean;
    requires_staff: boolean;
    sort_order: number;
    staff_ids: number[];
    booth_ids: number[];
    qualification_ids: number[];
}

interface MasterOption { id: number; code: string; name: string; is_active: boolean }

const props = defineProps<{
    service: ServiceFormData;
    staff: StaffOption[];
    analysisCategories: MasterOption[];
    taxCategories: MasterOption[];
    booths: { id: number; name: string; is_active: boolean }[];
    qualifications: MasterOption[];
}>();

const categorySuggestions = MESSAGES.mastersUi.services.categorySuggestions;

const form = useForm({
    name: props.service.name,
    duration_min: props.service.duration_min,
    price: props.service.price,
    category: props.service.category,
    analysis_category_id: props.service.analysis_category_id,
    tax_category_id: props.service.tax_category_id,
    color: props.service.color,
    is_online_bookable: props.service.is_online_bookable,
    requires_staff: props.service.requires_staff,
    sort_order: props.service.sort_order,
    staff_ids: [...props.service.staff_ids],
    booth_ids: [...props.service.booth_ids],
    qualification_ids: [...props.service.qualification_ids],
});

const submit = (): void => {
    form.put(`/admin/services/${props.service.id}`);
};
</script>

<template>
    <Head :title="fillMessage(MESSAGES.mastersUi.services.editHead, { name: service.name })" />

    <div class="service-form-shell">
        <PageHeader
            :title="MESSAGES.mastersUi.services.editTitle"
            :subtitle="fillMessage(MESSAGES.mastersUi.services.editSubtitle, { name: service.name })"
        />

        <v-form @submit.prevent="submit">
            <SectionCard>
                <section class="mb-6" aria-labelledby="service-edit-basic">
                    <h2 id="service-edit-basic" class="section-heading text-subtitle-1 mb-4">
                        {{ MESSAGES.mastersUi.services.basicInformation }}
                    </h2>
                    <v-text-field
                        v-model="form.name"
                        class="mb-1"
                        :label="MESSAGES.mastersUi.services.name"
                        :error-messages="form.errors.name"
                        maxlength="100"
                        required
                    />
                    <v-combobox
                        v-model="form.category"
                        class="mb-1"
                        :label="MESSAGES.mastersUi.services.category"
                        :items="categorySuggestions"
                        :error-messages="form.errors.category"
                        clearable
                    />
                    <div class="master-fields">
                        <v-select v-model="form.analysis_category_id" :label="MESSAGES.mastersUi.services.analysisCategoryField" :items="analysisCategories" item-title="name" item-value="id" clearable :error-messages="form.errors.analysis_category_id" />
                        <v-select v-model="form.tax_category_id" :label="MESSAGES.mastersUi.services.taxCategory" :items="taxCategories" item-title="name" item-value="id" clearable :error-messages="form.errors.tax_category_id" />
                    </div>
                    <div class="mb-4">
                        <ColorField v-model="form.color" :label="MESSAGES.mastersUi.services.displayColor" />
                        <div v-if="form.errors.color" class="text-error text-caption mt-1">
                            {{ form.errors.color }}
                        </div>
                    </div>
                </section>

                <v-divider class="mb-6" />

                <section aria-labelledby="service-edit-booking">
                    <h2 id="service-edit-booking" class="section-heading text-subtitle-1 mb-4">
                        {{ MESSAGES.mastersUi.services.reservationSettings }}
                    </h2>
                    <div class="number-fields mb-4">
                        <v-text-field
                            v-model.number="form.duration_min"
                            :label="MESSAGES.mastersUi.services.durationMinutes"
                            type="number"
                            min="5"
                            max="600"
                            :error-messages="form.errors.duration_min"
                            required
                        />
                        <MoneyField
                            v-model="form.price"
                            :label="MESSAGES.mastersUi.services.priceTaxIncluded"
                            :error-messages="form.errors.price"
                            required
                        />
                        <v-text-field
                            v-model.number="form.sort_order"
                            :label="MESSAGES.mastersUi.services.sortOrder"
                            type="number"
                            :error-messages="form.errors.sort_order"
                            required
                        />
                    </div>
                    <div class="switches mb-4">
                        <v-switch
                            v-model="form.is_online_bookable"
                            :label="MESSAGES.mastersUi.services.onlineBookable"
                            color="primary"
                            :error-messages="form.errors.is_online_bookable"
                            hide-details="auto"
                        />
                        <v-switch
                            v-model="form.requires_staff"
                            :label="MESSAGES.mastersUi.services.requiresStaff"
                            color="primary"
                            :error-messages="form.errors.requires_staff"
                            hide-details="auto"
                        />
                    </div>
                    <v-autocomplete
                        v-model="form.staff_ids"
                        :label="MESSAGES.mastersUi.services.eligibleStaff"
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
                                :subtitle="item.raw.is_bookable ? undefined : MESSAGES.mastersUi.services.bookingStopped"
                            />
                        </template>
                    </v-autocomplete>
                    <ServiceResourceFields
                        v-model:booth-ids="form.booth_ids"
                        v-model:qualification-ids="form.qualification_ids"
                        class="mt-2"
                        :booths="booths"
                        :qualifications="qualifications"
                        :booth-errors="form.errors.booth_ids"
                        :qualification-errors="form.errors.qualification_ids"
                    />
                </section>

                <div class="form-actions d-flex ga-3 flex-wrap">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        {{ MESSAGES.mastersUi.services.update }}
                    </v-btn>
                    <v-btn variant="text" href="/admin/services">{{ MESSAGES.mastersUi.services.cancel }}</v-btn>
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
