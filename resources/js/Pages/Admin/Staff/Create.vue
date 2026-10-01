<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ColorField, PageHeader, SectionCard } from '@/components/ark';

defineOptions({ layout: AdminLayout });

const roles = [
    { title: 'スタッフ', value: 'staff' },
    { title: 'マネージャー', value: 'manager' },
    { title: '管理者', value: 'admin' },
] as const;

const form = useForm({
    name: '',
    email: '',
    display_name: '',
    role: 'staff',
    color: '#888888',
    is_bookable: true,
});

const submit = (): void => {
    form.post('/admin/staff');
};
</script>

<template>
    <Head title="スタッフ作成" />

    <div class="ark-form-page">
        <PageHeader
            title="スタッフを追加"
            subtitle="作成後、入力したメールアドレスへパスワード設定リンクを送信します。"
        />

        <v-form @submit.prevent="submit">
            <SectionCard title="基本情報">
                <v-text-field
                    v-model="form.name"
                    label="氏名"
                    autocomplete="name"
                    hide-details="auto"
                    class="ark-field-name mb-4"
                    :error-messages="form.errors.name"
                    required
                />
                <v-text-field
                    v-model="form.display_name"
                    label="画面表示名"
                    hide-details="auto"
                    class="ark-field-name mb-4"
                    :error-messages="form.errors.display_name"
                    required
                />
                <v-text-field
                    v-model="form.email"
                    label="メールアドレス（ログインID）"
                    type="email"
                    autocomplete="email"
                    hide-details="auto"
                    class="mb-4"
                    :error-messages="form.errors.email"
                    required
                />
                <ColorField v-model="form.color" label="表示色" />
                <div v-if="form.errors.color" class="text-error text-caption mt-1">
                    {{ form.errors.color }}
                </div>
            </SectionCard>

            <SectionCard title="予約設定" class="mt-4">
                <v-select
                    v-model="form.role"
                    label="ロール"
                    :items="roles"
                    hide-details="auto"
                    style="max-width: 220px"
                    :error-messages="form.errors.role"
                />
                <v-switch
                    v-model="form.is_bookable"
                    label="予約を受け付ける"
                    color="primary"
                    hide-details
                    class="mt-2"
                    :error-messages="form.errors.is_bookable"
                />
            </SectionCard>

            <div class="ark-form-page__actions">
                <v-spacer />
                <v-btn variant="text" href="/admin/staff">キャンセル</v-btn>
                <v-btn type="submit" color="primary" size="large" :loading="form.processing">
                    作成してメールを送信
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
