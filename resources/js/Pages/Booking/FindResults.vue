<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { reservationStatusLabel } from '@/design/tokens';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { formatDateObject } from '@/utils/dateFormat';

defineOptions({ layout: GuestBookingLayout });

interface ReservationResult {
    id: number;
    date: string;
    time: string;
    service_name: string;
    status: string;
    confirmation_url: string;
}

defineProps<{
    reservations: ReservationResult[];
}>();

</script>

<template>
    <Head :title="MESSAGES.customerUi.bookingFindResults.title" />

    <PageHeader :title="MESSAGES.customerUi.bookingFindResults.title" :subtitle="MESSAGES.customerUi.bookingFindResults.subtitle" />

    <v-alert v-if="reservations.length === 0" type="info" variant="tonal">
        {{ MESSAGES.reservation.notFoundByPhone }}
    </v-alert>

    <div v-else class="result-list">
        <SectionCard v-for="reservation in reservations" :key="reservation.id">
            <div class="d-flex align-start justify-space-between ga-3 mb-2">
                <div>
                    <div class="font-weight-bold">{{ reservation.service_name }}</div>
                    <div class="text-body-2 text-medium-emphasis mt-1">
                        {{ formatDateObject(new Date(`${reservation.date}T${reservation.time}:00`), 'long') }}
                    </div>
                </div>
                <StatusChip
                    :status="reservation.status"
                    :label="reservationStatusLabel(reservation.status)"
                />
            </div>
            <v-btn
                :href="reservation.confirmation_url"
                color="primary"
                variant="outlined"
                block
                class="mt-3"
            >
                {{ MESSAGES.customerUi.bookingFindResults.open }}
            </v-btn>
        </SectionCard>
    </div>

    <v-btn href="/booking/find" variant="text" block class="mt-4">
        {{ MESSAGES.customerUi.bookingFindResults.searchAgain }}
    </v-btn>
</template>

<style scoped>
.result-list {
    display: grid;
    gap: var(--ark-space-4);
}
</style>
