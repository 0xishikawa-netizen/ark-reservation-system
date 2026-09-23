<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface PermissionItem {
    name: string;
    label: string;
}

interface PermissionGroup {
    label: string;
    permissions: PermissionItem[];
}

interface RolePermissions {
    staff: string[];
    manager: string[];
    admin: string[];
}

const props = defineProps<{
    groups: PermissionGroup[];
    role_permissions: RolePermissions;
}>();

type EditableRole = 'staff' | 'manager';

// この画面のチェックボックスに出るのは props.groups に含まれる権限のみ。
// 'admin.access'（常時付与）や 'settings.manage' / 'integrations.manage'（admin 専用の
// 設定系操作としてこの画面からは委譲不可）など、一覧に出ない権限は状態にも
// 送信ペイロードにも含めない（サーバー側で現状維持される）。
const editablePermissionNames = new Set(
    props.groups.flatMap((group) => group.permissions.map((permission) => permission.name)),
);
const onlyEditable = (names: string[]): string[] => names.filter((name) => editablePermissionNames.has(name));

const selected = reactive<Record<EditableRole, Set<string>>>({
    staff: new Set(onlyEditable(props.role_permissions.staff)),
    manager: new Set(onlyEditable(props.role_permissions.manager)),
});

const adminPermissionNames = computed(() => new Set(props.role_permissions.admin));

function isChecked(role: EditableRole, permission: string): boolean {
    return selected[role].has(permission);
}

function toggle(role: EditableRole, permission: string): void {
    const set = selected[role];

    if (set.has(permission)) {
        set.delete(permission);
    } else {
        set.add(permission);
    }
}

const processing = ref(false);

function submit(): void {
    processing.value = true;

    router.patch(
        '/admin/settings/roles',
        {
            staff: [...selected.staff],
            manager: [...selected.manager],
        },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="ロール権限管理" />

    <PageHeader
        title="ロール権限管理"
        subtitle="「スタッフ」「マネージャー」ロールが見られる画面・できる操作を設定します。"
    />

    <v-alert type="info" variant="tonal" class="mb-5">
        管理者（admin）は常にすべての権限を持ちます。ここでは変更できません。
        また、どのロールでも管理画面への入場自体は常に許可されます。
    </v-alert>

    <SectionCard
        v-for="group in groups"
        :key="group.label"
        :title="group.label"
        class="mb-4"
    >
        <v-table density="comfortable">
            <thead>
                <tr>
                    <th>画面・操作</th>
                    <th class="text-center" style="width: 96px">スタッフ</th>
                    <th class="text-center" style="width: 96px">マネージャー</th>
                    <th class="text-center" style="width: 96px">管理者</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="permission in group.permissions" :key="permission.name">
                    <td>{{ permission.label }}</td>
                    <td class="text-center">
                        <v-checkbox
                            :model-value="isChecked('staff', permission.name)"
                            color="primary"
                            hide-details
                            density="compact"
                            @update:model-value="toggle('staff', permission.name)"
                        />
                    </td>
                    <td class="text-center">
                        <v-checkbox
                            :model-value="isChecked('manager', permission.name)"
                            color="primary"
                            hide-details
                            density="compact"
                            @update:model-value="toggle('manager', permission.name)"
                        />
                    </td>
                    <td class="text-center">
                        <v-icon
                            :icon="adminPermissionNames.has(permission.name)
                                ? 'mdi-check-circle'
                                : 'mdi-minus'"
                            :color="adminPermissionNames.has(permission.name) ? 'success' : undefined"
                            size="20"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>

    <div class="d-flex ga-3 mt-2 mb-6">
        <v-btn color="primary" size="large" :loading="processing" @click="submit">
            保存
        </v-btn>
    </div>
</template>
