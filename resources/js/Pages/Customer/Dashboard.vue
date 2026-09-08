<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface NextReservation {
    id: number;
    service_name: string;
    staff_name: string | null;
    starts_at: string;
    status: string;
    status_label: string;
}

interface MembershipSummary {
    status: string;
    status_label: string;
    current_period_end: string | null;
    available: number;
    cancel_at_period_end: boolean;
}

interface TicketSummary {
    total_available: number;
    nearest_expires_at: string | null;
    wallet_count: number;
}

interface RecentPayment {
    amount: number;
    status_label: string;
    kind_label: string;
    created_at: string;
}

const props = defineProps<{
    next_reservation: NextReservation | null;
    upcoming_count: number;
    membership: MembershipSummary | null;
    tickets: TicketSummary;
    recent_payment: RecentPayment | null;
    attention: string[];
}>();

const yen = (value: number): string => `¥${value.toLocaleString('ja-JP')}`;

const go = (href: string): void => {
    router.visit(href);
};
</script>

<template>
    <Head title="マイページ" />

    <v-alert
        v-if="props.attention.length > 0"
        type="warning"
        variant="tonal"
        class="mb-4"
        density="comfortable"
    >
        <ul class="pl-4 mb-0">
            <li v-for="(message, index) in props.attention" :key="index">{{ message }}</li>
        </ul>
    </v-alert>

    <v-card class="mb-4">
        <v-card-item>
            <v-card-title class="text-subtitle-1">次回のご予約</v-card-title>
        </v-card-item>
        <v-card-text>
            <template v-if="props.next_reservation">
                <div class="text-h6">{{ props.next_reservation.starts_at }}</div>
                <div class="text-body-2 text-medium-emphasis">
                    {{ props.next_reservation.service_name }}
                    <template v-if="props.next_reservation.staff_name">
                        ／ {{ props.next_reservation.staff_name }}
                    </template>
                </div>
                <v-chip size="small" class="mt-2" color="primary" variant="tonal">
                    {{ props.next_reservation.status_label }}
                </v-chip>
                <div class="mt-3">
                    <v-btn
                        size="small"
                        variant="text"
                        color="primary"
                        @click="go(`/mypage/reservations/${props.next_reservation.id}`)"
                    >
                        予約の詳細
                    </v-btn>
                    <v-btn size="small" variant="text" @click="go('/mypage/reservations')">
                        予約一覧（今後 {{ props.upcoming_count }} 件）
                    </v-btn>
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-3">今後のご予約はありません。</p>
            </template>
            <v-btn color="primary" block class="mt-2" @click="go('/reserve')">予約する</v-btn>
        </v-card-text>
    </v-card>

    <v-card class="mb-4">
        <v-card-item>
            <v-card-title class="text-subtitle-1">利用権（会員）</v-card-title>
        </v-card-item>
        <v-card-text>
            <template v-if="props.membership">
                <div class="d-flex align-center ga-2">
                    <v-chip size="small" color="primary" variant="tonal">
                        {{ props.membership.status_label }}
                    </v-chip>
                    <span class="text-body-2">当期残り {{ props.membership.available }} 回</span>
                </div>
                <div
                    v-if="props.membership.current_period_end"
                    class="text-body-2 text-medium-emphasis mt-1"
                >
                    次回更新：{{ props.membership.current_period_end }}
                </div>
                <div
                    v-if="props.membership.cancel_at_period_end"
                    class="text-body-2 text-warning mt-1"
                >
                    当期末で解約予定です。
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-0">利用権は未加入です。</p>
            </template>
            <v-btn variant="text" color="primary" class="mt-2" @click="go('/mypage/membership')">
                会員ページへ
            </v-btn>
        </v-card-text>
    </v-card>

    <v-card class="mb-4">
        <v-card-item>
            <v-card-title class="text-subtitle-1">回数券</v-card-title>
        </v-card-item>
        <v-card-text>
            <template v-if="props.tickets.total_available > 0">
                <div class="text-h6">残り {{ props.tickets.total_available }} 回</div>
                <div
                    v-if="props.tickets.nearest_expires_at"
                    class="text-body-2 text-medium-emphasis mt-1"
                >
                    有効期限（最短）：{{ props.tickets.nearest_expires_at }}
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-0">利用できる回数券はありません。</p>
            </template>
            <v-btn variant="text" color="primary" class="mt-2" @click="go('/mypage/tickets')">
                回数券ページへ
            </v-btn>
        </v-card-text>
    </v-card>

    <v-card>
        <v-card-item>
            <v-card-title class="text-subtitle-1">直近のお支払い</v-card-title>
        </v-card-item>
        <v-card-text>
            <template v-if="props.recent_payment">
                <div class="text-h6">{{ yen(props.recent_payment.amount) }}</div>
                <div class="text-body-2 text-medium-emphasis mt-1">
                    {{ props.recent_payment.kind_label }} ／ {{ props.recent_payment.status_label }}
                </div>
                <div class="text-body-2 text-medium-emphasis">
                    {{ props.recent_payment.created_at }}
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-0">お支払い履歴はありません。</p>
            </template>
            <v-btn variant="text" color="primary" class="mt-2" @click="go('/mypage/payments')">
                支払い履歴へ
            </v-btn>
        </v-card-text>
    </v-card>
</template>
