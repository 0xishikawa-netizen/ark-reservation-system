<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

/**
 * 削除済みマスタの一覧と復元（admin 専用）。一覧画面の下に折りたたんで置く。
 */
const props = defineProps<{
    type: string;
    label: string;
    items: Array<{ id: number; name: string; deleted_at: string | null }>;
}>();

const page = usePage();
const canRestore = computed(() => page.props.auth?.can?.mastersDelete === true);
const restoringId = ref<number | null>(null);

function restore(id: number): void {
    restoringId.value = id;
    router.post(`/admin/masters/${props.type}/${id}/restore`, {}, {
        preserveScroll: true,
        onFinish: () => {
            restoringId.value = null;
        },
    });
}
</script>

<template>
    <v-expansion-panels v-if="canRestore && items.length > 0" class="mt-4" variant="accordion">
        <v-expansion-panel>
            <v-expansion-panel-title>
                <v-icon icon="mdi-delete-restore" size="18" class="mr-2" />
                {{ fillMessage(MESSAGES.masters.trashedTitle, { label: label, count: String(items.length) }) }}
            </v-expansion-panel-title>
            <v-expansion-panel-text>
                <v-table density="compact">
                    <thead>
                        <tr><th>{{ MESSAGES.customerUi.trashedMasters.name }}</th><th>{{ MESSAGES.customerUi.trashedMasters.deletedAt }}</th><th class="text-end" /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id">
                            <td>{{ item.name }}</td>
                            <td>{{ item.deleted_at ?? '' }}</td>
                            <td class="text-end">
                                <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-restore" :loading="restoringId === item.id" @click="restore(item.id)">{{ MESSAGES.customerUi.trashedMasters.restore }}</v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-expansion-panel-text>
        </v-expansion-panel>
    </v-expansion-panels>
</template>
