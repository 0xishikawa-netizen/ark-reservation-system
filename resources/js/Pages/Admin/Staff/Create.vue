<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

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

    <v-card max-width="640" title="スタッフ作成">
        <v-card-text>
            <p class="mb-4">
                作成後、入力したメールアドレスへパスワード設定リンクを送信します。
            </p>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="氏名"
                    autocomplete="name"
                    :error-messages="form.errors.name"
                    required
                />
                <v-text-field
                    v-model="form.display_name"
                    label="画面表示名"
                    :error-messages="form.errors.display_name"
                    required
                />
                <v-text-field
                    v-model="form.email"
                    label="メールアドレス"
                    type="email"
                    autocomplete="email"
                    :error-messages="form.errors.email"
                    required
                />
                <v-select
                    v-model="form.role"
                    label="ロール"
                    :items="roles"
                    :error-messages="form.errors.role"
                />
                <v-text-field
                    v-model="form.color"
                    label="表示色"
                    type="color"
                    :error-messages="form.errors.color"
                />
                <v-checkbox
                    v-model="form.is_bookable"
                    label="予約を受け付ける"
                    :error-messages="form.errors.is_bookable"
                />
                <div class="d-flex ga-3">
                    <v-btn
                        type="submit"
                        color="primary"
                        :loading="form.processing"
                    >
                        作成してメールを送信
                    </v-btn>
                    <v-btn variant="text" href="/admin/staff">
                        キャンセル
                    </v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
