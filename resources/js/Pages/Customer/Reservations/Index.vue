<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateTime } from '@/utils/dateFormat';

defineOptions({ layout: CustomerLayout });

interface ReservationListItem {
    id: number;
    starts_at: string;
    ends_at: string;
    service_name: string;
    staff_name: string | null;
    status: string;
}

interface GroupedReservations {
    upcoming: ReservationListItem[];
    past: ReservationListItem[];
}

defineProps<{ reservations: GroupedReservations }>();

const statusLabels: Record<string, string> = MESSAGES.customerUi.reservations.statuses;

function staffLabel(name: string | null): string {
    return fillMessage(MESSAGES.customerUi.reservations.staff, { name: name ?? MESSAGES.customerUi.reservations.staffUndecided });
}

function pastSubtitle(reservation: ReservationListItem): string {
    return fillMessage(MESSAGES.customerUi.reservations.pastSubtitle, { date: formatDateTime(reservation.starts_at, 'long'), staff: staffLabel(reservation.staff_name), status: statusLabels[reservation.status] ?? reservation.status });
}
</script>

<template>
    <Head :title="MESSAGES.customerUi.reservations.title" />

    <div class="d-flex align-center justify-space-between mb-4 ga-3">
        <h1 class="text-h5">{{ MESSAGES.customerUi.reservations.title }}</h1>
        <v-btn color="primary" href="/reserve">{{ MESSAGES.customerUi.reservations.newReservation }}</v-btn>
    </div>

    <section aria-labelledby="upcoming-heading" class="mb-8">
        <h2 id="upcoming-heading" class="text-h6 mb-3">{{ MESSAGES.customerUi.reservations.upcoming }}</h2>
        <v-alert
            v-if="reservations.upcoming.length === 0"
            type="info"
            variant="tonal"
        >
            {{ MESSAGES.reservation.noUpcoming }}
        </v-alert>
        <div v-else class="d-flex flex-column ga-3">
            <v-card
                v-for="reservation in reservations.upcoming"
                :key="reservation.id"
                :href="`/mypage/reservations/${reservation.id}`"
                hover
            >
                <v-card-title class="text-subtitle-1">
                    {{ formatDateTime(reservation.starts_at, 'long') }}
                </v-card-title>
                <v-card-text>
                    <div class="font-weight-medium">{{ reservation.service_name }}</div>
                    <div class="text-medium-emphasis">
                        {{ staffLabel(reservation.staff_name) }}
                    </div>
                    <v-chip size="small" color="primary" class="mt-2">
                        {{ statusLabels[reservation.status] ?? reservation.status }}
                    </v-chip>
                </v-card-text>
            </v-card>
        </div>
    </section>

    <section aria-labelledby="past-heading">
        <h2 id="past-heading" class="text-h6 mb-3">{{ MESSAGES.customerUi.reservations.past }}</h2>
        <v-alert
            v-if="reservations.past.length === 0"
            type="info"
            variant="tonal"
        >
            {{ MESSAGES.reservation.noPast }}
        </v-alert>
        <v-list v-else bg-color="transparent" class="pa-0">
            <v-list-item
                v-for="reservation in reservations.past"
                :key="reservation.id"
                :href="`/mypage/reservations/${reservation.id}`"
                class="bg-white rounded mb-2"
                :title="reservation.service_name"
                :subtitle="pastSubtitle(reservation)"
            />
        </v-list>
    </section>
</template>
