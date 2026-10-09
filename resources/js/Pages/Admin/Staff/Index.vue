<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { EmptyState, EmptyValue, PageHeader, SectionCard, StatusChip, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
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
    trashed?: Array<{ id: number; name: string; deleted_at: string | null }>;
    staff: StaffMember[];
}>();
</script>

<template>
    <Head :title="MESSAGES.mastersUi.staff.title" />

    <PageHeader :title="MESSAGES.mastersUi.staff.title" :subtitle="MESSAGES.mastersUi.staff.subtitle">
        <template #actions>
            <v-btn
                v-if="page.props.auth.can.shiftsManage"
                variant="outlined"
                prepend-icon="mdi-calendar-clock-outline"
                href="/admin/staff-shifts"
            >
                {{ MESSAGES.mastersUi.staff.shifts }}
            </v-btn>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-account-plus-outline" href="/admin/staff/create">
                {{ MESSAGES.mastersUi.staff.add }}
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard :title="MESSAGES.mastersUi.staff.list" class="ark-table-section">
        <v-table>
            <thead>
                <tr>
                    <th>{{ MESSAGES.mastersUi.staff.displayName }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.name }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.email }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.role }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.color }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.booking }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.login }}</th>
                    <th>{{ MESSAGES.mastersUi.staff.sortOrder }}</th>
                    <th class="text-right">{{ MESSAGES.mastersUi.staff.operation }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="member in staff" :key="member.user_id">
                    <td>{{ member.display_name }}</td>
                    <td>{{ member.name }}</td>
                    <td>{{ member.email }}</td>
                    <td><template v-if="member.role">{{ member.role }}</template><EmptyValue v-else :label="MESSAGES.common.notSet" /></td>
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
                            :label="member.is_bookable ? MESSAGES.mastersUi.staff.available : MESSAGES.mastersUi.staff.unavailable"
                        />
                    </td>
                    <td>
                        <StatusChip
                            :status="member.is_active ? 'active' : 'no_show'"
                            :label="member.is_active ? MESSAGES.mastersUi.staff.active : MESSAGES.mastersUi.staff.inactive"
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
                                {{ MESSAGES.mastersUi.staff.edit }}
                            </v-btn>
                            <MasterDeleteButton type="staff" :id="member.user_id" :name="member.display_name" :label="MESSAGES.mastersUi.staff.masterLabel" />
                            <v-btn
                                size="small"
                                variant="outlined"
                                prepend-icon="mdi-calendar-clock-outline"
                                class="staff-row-actions__btn"
                                :href="`/admin/staff-shifts?staff_id=${member.user_id}`"
                            >
                                {{ MESSAGES.mastersUi.staff.shifts }}
                            </v-btn>
                        </div>
                    </td>
                </tr>
                <tr v-if="staff.length === 0">
                    <td colspan="9">
                        <EmptyState
                            icon="mdi-account-group-outline"
                            :title="MESSAGES.mastersUi.staff.emptyTitle"
                            :description="MESSAGES.mastersUi.staff.emptyDescription"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>
    <TrashedMasterList type="staff" :label="MESSAGES.mastersUi.staff.masterLabel" :items="trashed ?? []" />
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
