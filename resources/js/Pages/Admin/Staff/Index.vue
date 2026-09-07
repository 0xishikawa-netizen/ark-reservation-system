<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface StaffMember {
    user_id: number;
    name: string;
    email: string;
    display_name: string;
    color: string;
    is_bookable: boolean;
}

defineProps<{
    staff: StaffMember[];
}>();
</script>

<template>
    <Head title="スタッフ" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">スタッフ</h1>
        <v-btn color="primary" href="/admin/staff/create">
            スタッフを追加
        </v-btn>
    </div>

    <v-card>
        <v-table>
            <thead>
                <tr>
                    <th>表示名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>色</th>
                    <th>予約受付</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="member in staff" :key="member.user_id">
                    <td>{{ member.display_name }}</td>
                    <td>{{ member.name }}</td>
                    <td>{{ member.email }}</td>
                    <td>
                        <span
                            class="d-inline-block rounded-circle mr-2"
                            :style="{
                                backgroundColor: member.color,
                                height: '16px',
                                width: '16px',
                            }"
                        />
                        {{ member.color }}
                    </td>
                    <td>{{ member.is_bookable ? '可' : '不可' }}</td>
                </tr>
                <tr v-if="staff.length === 0">
                    <td colspan="5" class="text-center text-medium-emphasis py-8">
                        スタッフはまだ登録されていません。
                    </td>
                </tr>
            </tbody>
        </v-table>
    </v-card>
</template>
