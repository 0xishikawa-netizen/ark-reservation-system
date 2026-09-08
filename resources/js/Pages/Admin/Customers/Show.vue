<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface CustomerProfile {
    user_id: number;
    name: string;
    kana: string;
    phone: string | null;
    birthday: string | null;
    gender: string | null;
    note: string | null;
    email: string;
    email_verified: boolean;
    created_via: string;
    created_at: string | null;
}

defineProps<{ customer: CustomerProfile }>();

const page = usePage();

const display = (value: string | null): string => value || '—';

const genderLabel = (value: string | null): string => {
    const labels: Record<string, string> = {
        male: '男性',
        female: '女性',
        other: 'その他',
    };

    return value ? (labels[value] ?? value) : '—';
};

const createdViaLabel = (value: string): string => {
    const labels: Record<string, string> = {
        web: 'Web',
        admin: '管理画面',
        migration: '移行',
    };

    return labels[value] ?? value;
};
</script>

<template>
    <Head :title="`${customer.name}の顧客情報`" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">顧客詳細</h1>
        <div class="d-flex ga-3">
            <v-btn
                variant="tonal"
                :href="`/admin/customers/${customer.user_id}/tickets`"
            >
                回数券
            </v-btn>
            <v-btn
                v-if="page.props.auth.can.customersManage"
                color="primary"
                :href="`/admin/customers/${customer.user_id}/edit`"
            >
                編集
            </v-btn>
        </div>
    </div>

    <v-card max-width="840" title="基本情報">
        <v-list lines="two">
            <v-list-item title="氏名" :subtitle="customer.name" />
            <v-list-item title="カナ" :subtitle="customer.kana" />
            <v-list-item title="電話番号" :subtitle="display(customer.phone)" />
            <v-list-item title="生年月日" :subtitle="display(customer.birthday)" />
            <v-list-item title="性別" :subtitle="genderLabel(customer.gender)" />
            <v-list-item title="メールアドレス" :subtitle="customer.email" />
            <v-list-item
                title="メール認証"
                :subtitle="customer.email_verified ? '認証済み' : '未認証'"
            />
            <v-list-item
                title="登録経路"
                :subtitle="createdViaLabel(customer.created_via)"
            />
            <v-list-item title="登録日" :subtitle="display(customer.created_at)" />
            <v-list-item title="メモ" :subtitle="display(customer.note)" />
        </v-list>
        <v-card-actions>
            <v-btn variant="text" href="/admin/customers">一覧へ戻る</v-btn>
        </v-card-actions>
    </v-card>
</template>
