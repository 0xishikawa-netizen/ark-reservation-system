<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const page = usePage();

interface StaffMember {
    user_id: number;
    name: string;
    email: string;
    display_name: string;
    color: string;
    is_bookable: boolean;
    sort_order: number;
    role: string | null;
}

defineProps<{
    staff: StaffMember[];
}>();
</script>

<template>
    <Head title="スタッフ" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">スタッフ</h1>
        <div class="d-flex ga-3">
            <v-btn
                v-if="page.props.auth.can.shiftsManage"
                variant="tonal"
                href="/admin/staff-shifts"
            >
                勤務枠
            </v-btn>
            <v-btn color="primary" href="/admin/staff/create">
                スタッフを追加
            </v-btn>
        </div>
    </div>

    <v-card>
        <v-table>
            <thead>
                <tr>
                    <th>表示名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>ロール</th>
                    <th>色</th>
                    <th>予約受付</th>
                    <th>表示順</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="member in staff" :key="member.user_id">
                    <td>{{ member.display_name }}</td>
                    <td>{{ member.name }}</td>
                    <td>{{ member.email }}</td>
                    <td>{{ member.role ?? '未設定' }}</td>
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
                    <td>{{ member.sort_order }}</td>
                    <td class="text-no-wrap">
                        <v-btn
                            v-if="page.props.auth.can.shiftsManage"
                            size="small"
                            variant="text"
                            :href="`/admin/staff/${member.user_id}/edit`"
                        >
                            編集
                        </v-btn>
                        <v-btn
                            size="small"
                            variant="text"
                            :href="`/admin/staff-shifts?staff_id=${member.user_id}`"
                        >
                            勤務枠
                        </v-btn>
                    </td>
                </tr>
                <tr v-if="staff.length === 0">
                    <td colspan="8" class="text-center text-medium-emphasis py-8">
                        スタッフはまだ登録されていません。
                    </td>
                </tr>
            </tbody>
        </v-table>
    </v-card>
</template>
