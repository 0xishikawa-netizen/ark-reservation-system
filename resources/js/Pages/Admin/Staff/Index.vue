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
    is_active: boolean;
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
                variant="outlined"
                prepend-icon="mdi-calendar-clock-outline"
                href="/admin/staff-shifts"
            >
                勤務枠
            </v-btn>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-account-plus-outline" href="/admin/staff/create">
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
                    <th>ログイン</th>
                    <th>表示順</th>
                    <th class="text-right">操作</th>
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
                    <td>
                        <StatusChip
                            :status="member.is_active ? 'active' : 'no_show'"
                            :label="member.is_active ? '有効' : '無効'"
                        />
                    </td>
                    <td>{{ member.sort_order }}</td>
                    <td class="text-no-wrap">
                        <div class="staff-row-actions">
                            <v-btn
                                v-if="page.props.auth.can.shiftsManage"
                                size="small"
                                variant="tonal"
                                color="primary"
                                prepend-icon="mdi-pencil-outline"
                                class="staff-row-actions__btn"
                                :href="`/admin/staff/${member.user_id}/edit`"
                            >
                                編集
                            </v-btn>
                            <v-btn
                                size="small"
                                variant="outlined"
                                prepend-icon="mdi-calendar-clock-outline"
                                class="staff-row-actions__btn"
                                :href="`/admin/staff-shifts?staff_id=${member.user_id}`"
                            >
                                勤務枠
                            </v-btn>
                        </div>
                    </td>
                </tr>
                <tr v-if="staff.length === 0">
                    <td colspan="9">
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

.staff-row-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--ark-space-2);
}

.staff-row-actions__btn {
    min-width: 104px;
}
</style>
