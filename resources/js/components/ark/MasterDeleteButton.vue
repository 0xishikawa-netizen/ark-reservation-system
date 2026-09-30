<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MESSAGES } from '@/constants/messages';

/**
 * マスタの削除ボタン（admin 専用）。必ず確認ダイアログを出し、使用中で削除できない時は理由をダイアログ内に表示する。
 */
const props = defineProps<{
    /** MasterDeletionService::TYPES のキー（services / booths / products / staff / ticket-products / membership-plans）。 */
    type: string;
    id: number;
    name: string;
    label: string;
}>();

const page = usePage();
const canDelete = computed(() => page.props.auth?.can?.mastersDelete === true);
const open = ref(false);
const processing = ref(false);
const error = ref<string | null>(null);

function confirm(): void {
    processing.value = true;
    error.value = null;
    router.delete(`/admin/masters/${props.type}/${props.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: (errors) => {
            error.value = errors.delete ?? MESSAGES.masters.deleteFailed;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <template v-if="canDelete">
        <v-btn
            size="small"
            variant="text"
            color="error"
            prepend-icon="mdi-delete-outline"
            :aria-label="`${name}を削除`"
            @click="open = true; error = null"
        >
            削除
        </v-btn>
        <v-dialog v-model="open" max-width="440">
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">{{ MESSAGES.masters.confirmTitle.replace('{label}', label) }}</v-card-title>
                <v-card-text>
                    <p class="mb-2">「{{ name }}」を削除します。</p>
                    <p class="text-body-2 text-medium-emphasis">{{ MESSAGES.masters.confirmBody }}</p>
                    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">{{ error }}</v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="open = false">やめる</v-btn>
                    <v-btn color="error" variant="flat" :loading="processing" :disabled="error !== null" @click="confirm">削除する</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </template>
</template>
