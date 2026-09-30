<script setup lang="ts">
/**
 * 予約／スタッフ予定を選ぶ・切り替えるボタン。空き枠クリック後の選択、予約パネル⇔予定パネルの切り替えで
 * 同じ見た目にそろえる（白地のカード＋左の色帯・アイコンで区別。塗りつぶしにすると説明文が読みにくいため）。
 */
withDefaults(defineProps<{
    kind: 'reservation' | 'block';
    title: string;
    description?: string | null;
    compact?: boolean;
}>(), {
    description: null,
    compact: false,
});

const emit = defineEmits<{ click: [] }>();
</script>

<template>
    <button
        type="button"
        class="pcc"
        :class="[`pcc--${kind}`, { 'pcc--compact': compact }]"
        @click="emit('click')"
    >
        <span class="pcc__icon">
            <v-icon :icon="kind === 'reservation' ? 'mdi-calendar-plus-outline' : 'mdi-calendar-clock-outline'" :size="compact ? 16 : 20" />
        </span>
        <span class="pcc__body">
            <span class="pcc__title">{{ title }}</span>
            <span v-if="description" class="pcc__desc">{{ description }}</span>
        </span>
        <v-icon icon="mdi-chevron-right" size="18" class="pcc__arrow" />
    </button>
</template>

<style scoped>
.pcc {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--ark-space-3);
    width: 100%;
    padding: var(--ark-space-3);
    padding-left: calc(var(--ark-space-3) + 4px);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.15);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    overflow: hidden;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.12s ease, background-color 0.12s ease;
}

.pcc--compact {
    padding-top: var(--ark-space-2);
    padding-bottom: var(--ark-space-2);
}

.pcc::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 4px;
}

.pcc--reservation::before {
    background: rgb(var(--v-theme-primary));
}

.pcc--block::before {
    background: rgb(var(--v-theme-secondary));
}

.pcc--reservation:hover {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.06);
}

.pcc--block:hover {
    border-color: rgb(var(--v-theme-secondary));
    background: rgba(var(--v-theme-secondary), 0.07);
}

.pcc__icon {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
}

.pcc--compact .pcc__icon {
    width: 28px;
    height: 28px;
}

.pcc--block .pcc__icon {
    background: rgba(var(--v-theme-secondary), 0.14);
    color: rgb(var(--v-theme-secondary));
}

.pcc__body {
    display: flex;
    flex: 1 1 auto;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
}

.pcc__title {
    font-size: 0.8125rem;
    font-weight: 800;
    color: rgb(var(--v-theme-on-surface));
}

.pcc__desc {
    font-size: 0.6875rem;
    line-height: 1.5;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.pcc__arrow {
    flex: 0 0 auto;
    color: rgba(var(--v-theme-on-surface), 0.45);
}
</style>
