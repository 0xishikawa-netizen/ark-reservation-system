<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

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

const statusLabels: Record<string, string> = {
    confirmed: '予約確定',
    completed: '完了',
    no_show: '来店なし',
    canceled: 'キャンセル',
    expired: '期限切れ',
};

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));
}
</script>

<template>
    <Head title="予約一覧" />

    <div class="d-flex align-center justify-space-between mb-4 ga-3">
        <h1 class="text-h5">予約一覧</h1>
        <v-btn color="primary" href="/reserve">新しく予約する</v-btn>
    </div>

    <section aria-labelledby="upcoming-heading" class="mb-8">
        <h2 id="upcoming-heading" class="text-h6 mb-3">今後の予約</h2>
        <v-alert
            v-if="reservations.upcoming.length === 0"
            type="info"
            variant="tonal"
        >
            今後の予約はありません。
        </v-alert>
        <div v-else class="d-flex flex-column ga-3">
            <v-card
                v-for="reservation in reservations.upcoming"
                :key="reservation.id"
                :href="`/mypage/reservations/${reservation.id}`"
                hover
            >
                <v-card-title class="text-subtitle-1">
                    {{ formatDateTime(reservation.starts_at) }}
                </v-card-title>
                <v-card-text>
                    <div class="font-weight-medium">{{ reservation.service_name }}</div>
                    <div class="text-medium-emphasis">
                        担当: {{ reservation.staff_name ?? '未定' }}
                    </div>
                    <v-chip size="small" color="primary" class="mt-2">
                        {{ statusLabels[reservation.status] ?? reservation.status }}
                    </v-chip>
                </v-card-text>
            </v-card>
        </div>
    </section>

    <section aria-labelledby="past-heading">
        <h2 id="past-heading" class="text-h6 mb-3">過去の予約</h2>
        <v-alert
            v-if="reservations.past.length === 0"
            type="info"
            variant="tonal"
        >
            過去の予約はありません。
        </v-alert>
        <v-list v-else bg-color="transparent" class="pa-0">
            <v-list-item
                v-for="reservation in reservations.past"
                :key="reservation.id"
                :href="`/mypage/reservations/${reservation.id}`"
                class="bg-white rounded mb-2"
                :title="reservation.service_name"
                :subtitle="`${formatDateTime(reservation.starts_at)} / 担当: ${reservation.staff_name ?? '未定'} / ${statusLabels[reservation.status] ?? reservation.status}`"
            />
        </v-list>
    </section>
</template>
