<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PageHeader, SectionCard } from '@/components/ark';
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

    <PageHeader
        title="プロフィール"
        subtitle="ご登録いただいているお客様情報を確認できます。"
    />

    <SectionCard title="登録情報">
        <v-list lines="two" class="profile-list">
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

        <div class="d-flex ga-3 flex-wrap mt-4">
            <v-btn color="primary" variant="flat" href="/mypage/profile/edit">
                プロフィールを編集
            </v-btn>
            <v-btn variant="outlined" href="/mypage/security" prepend-icon="mdi-shield-account-outline">
                セキュリティ設定
            </v-btn>
        </div>
    </SectionCard>
</template>

<style scoped>
.profile-list {
    margin: calc(var(--ark-space-2) * -1) calc(var(--ark-space-4) * -1) 0;
    background: transparent;
}
</style>
