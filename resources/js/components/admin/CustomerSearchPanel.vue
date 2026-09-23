<script setup lang="ts">
import PanelShell from '@/components/admin/PanelShell.vue';
import { MESSAGES } from '@/constants/messages';

withDefaults(defineProps<{ canSearch: boolean; canGoBack?: boolean }>(), {
    canGoBack: false,
});

const emit = defineEmits<{
    close: [];
    create: [];
    back: [];
}>();
</script>

<template>
    <PanelShell
        title="ブッキングボード"
        icon="mdi-view-dashboard-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <p class="csp__hint">
            {{ MESSAGES.customer.searchPanelHint }}
        </p>

        <v-btn
            variant="flat"
            color="primary"
            size="small"
            prepend-icon="mdi-calendar-plus-outline"
            class="csp__createbtn"
            @click="emit('create')"
        >
            新規予約を作成
        </v-btn>
    </PanelShell>
</template>

<style scoped>
.csp__hint {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.6;
    color: rgba(var(--v-theme-on-surface), 0.74);
}

.csp__createbtn {
    width: 100%;
    margin-top: var(--ark-space-4);
}
</style>
