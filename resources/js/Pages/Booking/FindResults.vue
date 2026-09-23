<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { reservationStatusLabel } from '@/design/tokens';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';
import { MESSAGES } from '@/constants/messages';

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

const formatDate = (date: string, time: string): string =>
    new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(`${date}T${time}:00`));
</script>

<template>
    <Head title="予約検索結果" />

    <PageHeader title="予約検索結果" subtitle="電話番号に紐づく予約です。" />

    <v-alert v-if="reservations.length === 0" type="info" variant="tonal">
        {{ MESSAGES.reservation.notFoundByPhone }}
    </v-alert>

    <div v-else class="result-list">
        <SectionCard v-for="reservation in reservations" :key="reservation.id">
            <div class="d-flex align-start justify-space-between ga-3 mb-2">
                <div>
                    <div class="font-weight-bold">{{ reservation.service_name }}</div>
                    <div class="text-body-2 text-medium-emphasis mt-1">
                        {{ formatDate(reservation.date, reservation.time) }}
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
                予約内容を確認する
            </v-btn>
        </SectionCard>
    </div>

    <v-btn href="/booking/find" variant="text" block class="mt-4">
        別の電話番号で探す
    </v-btn>
</template>

<style scoped>
.result-list {
    display: grid;
    gap: var(--ark-space-4);
}
</style>
