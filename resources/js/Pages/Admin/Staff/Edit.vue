<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type StaffRole = 'staff' | 'manager' | 'admin';

interface StaffFormData {
    user_id: number;
    name: string;
    email: string;
    display_name: string;
    color: string;
    is_bookable: boolean;
    sort_order: number;
    role: StaffRole;
}

interface RoleOption {
    title: string;
    value: StaffRole;
}

const props = defineProps<{
    staff: StaffFormData;
    roles: RoleOption[];
}>();

const form = useForm({
    display_name: props.staff.display_name,
    color: props.staff.color,
    is_bookable: props.staff.is_bookable,
    sort_order: props.staff.sort_order,
    role: props.staff.role,
});

const submit = (): void => {
    form.put(`/admin/staff/${props.staff.user_id}`);
};

const deactivate = (): void => {
    if (!window.confirm(`${props.staff.display_name}を予約受付不可にしますか？`)) {
        return;
    }

    router.patch(`/admin/staff/${props.staff.user_id}/deactivate`);
};
</script>

<template>
    <Head :title="`${staff.display_name}を編集`" />

    <v-card max-width="720" title="スタッフ編集">
        <v-card-text>
            <v-list density="compact" class="mb-4">
                <v-list-item title="氏名" :subtitle="staff.name" />
                <v-list-item title="メールアドレス" :subtitle="staff.email" />
            </v-list>

            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.display_name"
                    label="表示名"
                    maxlength="50"
                    :error-messages="form.errors.display_name"
                    required
                />
                <v-text-field
                    v-model="form.color"
                    label="表示色"
                    type="color"
                    :error-messages="form.errors.color"
                />
                <v-text-field
                    v-model.number="form.sort_order"
                    label="表示順"
                    type="number"
                    min="0"
                    :error-messages="form.errors.sort_order"
                />
                <v-select
                    v-model="form.role"
                    label="ロール"
                    :items="roles"
                    :error-messages="form.errors.role"
                    required
                />
                <v-switch
                    v-model="form.is_bookable"
                    label="予約を受け付ける"
                    color="primary"
                    :error-messages="form.errors.is_bookable"
                />

                <v-alert type="info" variant="tonal" class="mb-5">
                    スタッフ情報の保存と無効化には、直近のパスワード確認が必要です。最後の管理者は降格できません。
                </v-alert>

                <div class="d-flex ga-3 flex-wrap">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        保存
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="outlined"
                        :disabled="!staff.is_bookable"
                        @click="deactivate"
                    >
                        予約受付を無効化
                    </v-btn>
                    <v-btn variant="text" href="/admin/staff">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
