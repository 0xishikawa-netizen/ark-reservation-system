<script setup lang="ts">
import PanelShell from '@/components/admin/PanelShell.vue';
import { MESSAGES } from '@/constants/messages';

withDefaults(defineProps<{
    canSearch: boolean;
    canGoBack?: boolean;
    /** 予約の作成権限（reservations.manage）。閲覧だけのスタッフには作成ボタンを出さない。 */
    canCreate?: boolean;
}>(), {
    canGoBack: false,
    canCreate: true,
});

const emit = defineEmits<{
    close: [];
    create: [];
    back: [];
}>();
</script>

<template>
    <PanelShell
        :title="MESSAGES.boardUi.customerSearch.boardTitle"
        icon="mdi-view-dashboard-outline"
        :show-back="canGoBack"
        @close="emit('close')"
        @back="emit('back')"
    >
        <p class="csp__hint">
            {{ MESSAGES.customer.searchPanelHint }}
        </p>

        <v-btn
            v-if="canCreate"
            variant="flat"
            color="primary"
            size="small"
            prepend-icon="mdi-calendar-plus-outline"
            class="csp__createbtn"
            @click="emit('create')"
        >
            {{ MESSAGES.boardUi.customerSearch.createReservation }}
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
