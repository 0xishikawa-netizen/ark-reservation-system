<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
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

    <PageHeader title="スタッフ" subtitle="スタッフ情報と予約受付状態を管理します。">
        <template #actions>
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
        </template>
    </PageHeader>

    <SectionCard title="スタッフ一覧" class="ark-table-section">
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
                    <td>
                        <StatusChip
                            :status="member.is_bookable ? 'active' : 'canceled'"
                            :label="member.is_bookable ? '可' : '不可'"
                        />
                    </td>
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
                    <td colspan="8">
                        <EmptyState
                            icon="mdi-account-group-outline"
                            title="スタッフはまだ登録されていません"
                            description="スタッフを追加すると、こちらで予約受付や勤務枠を管理できます。"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
