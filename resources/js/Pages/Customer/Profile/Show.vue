<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface CustomerProfile {
    user_id: number;
    name: string;
    kana: string;
    phone: string | null;
    birthday: string | null;
    gender: string | null;
    email: string;
    email_verified: boolean;
}

defineProps<{ customer: CustomerProfile }>();

const display = (value: string | null): string => value || '未登録';

const genderLabel = (value: string | null): string => {
    const labels: Record<string, string> = {
        male: '男性',
        female: '女性',
        other: 'その他',
    };

    return value ? (labels[value] ?? value) : '未登録';
};
</script>

<template>
    <Head title="プロフィール" />

    <v-card title="プロフィール">
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
        </v-list>

        <v-card-actions class="pa-4">
            <v-btn color="primary" href="/mypage/profile/edit">
                プロフィールを編集
            </v-btn>
        </v-card-actions>
    </v-card>
</template>
