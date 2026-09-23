<script setup lang="ts">
withDefaults(defineProps<{
    title: string;
    icon?: string | null;
    showBack?: boolean;
}>(), {
    icon: null,
    showBack: false,
});

const emit = defineEmits<{
    close: [];
    back: [];
}>();
</script>

<template>
    <header class="ph">
        <span class="ph__left">
            <button
                v-if="showBack"
                type="button"
                class="ph__back"
                aria-label="戻る"
                @click="emit('back')"
            >
                <v-icon icon="mdi-arrow-left" size="14" />
                <span>戻る</span>
            </button>
            <v-icon v-if="icon" :icon="icon" size="16" class="ph__icon" />
            <span class="ph__title">{{ title }}</span>
        </span>
        <span class="ph__actions">
            <v-btn
                icon="mdi-close"
                variant="text"
                size="small"
                color="white"
                aria-label="パネルを閉じる"
                @click="emit('close')"
            />
        </span>
    </header>
</template>

<style scoped>
/* Peak Manager 参考：パネルヘッダーは ARK navy、文字・アイコンは白（§背景navy・項目白）。 */
.ph {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-2);
    flex: 0 0 auto;
    min-width: 0;
    padding: var(--ark-space-2) var(--ark-space-2) var(--ark-space-2) var(--ark-space-3);
    background: rgb(var(--v-theme-primary));
}

.ph__left {
    display: flex;
    align-items: center;
    gap: 2px;
    min-width: 0;
    flex: 1 1 auto;
}

.ph__back {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex: 0 0 auto;
    height: 24px;
    margin-right: 4px;
    padding: 0 8px;
    border: 1px solid rgb(255 255 255 / 0.4);
    border-radius: 999px;
    background: none;
    color: #ffffff;
    font-size: 0.6875rem;
    font-weight: 700;
    cursor: pointer;
}

.ph__back:hover {
    background: rgb(255 255 255 / 0.14);
}

.ph__icon {
    flex: 0 0 auto;
    color: rgba(255, 255, 255, 0.85);
}

.ph__title {
    font-size: 0.8125rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}

.ph__actions {
    display: flex;
    align-items: center;
    gap: 2px;
}
</style>
