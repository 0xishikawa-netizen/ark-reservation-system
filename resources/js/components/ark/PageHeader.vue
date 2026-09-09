<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <header class="ark-page-header mb-6">
        <div class="ark-page-header__copy">
            <h1 class="text-h5 text-sm-h4">{{ title }}</h1>
            <p v-if="subtitle" class="text-body-2 text-medium-emphasis mt-1 mb-0">
                {{ subtitle }}
            </p>
        </div>

        <div v-if="$slots.actions" class="ark-page-header__actions">
            <slot name="actions" />
        </div>
    </header>
</template>

<style scoped>
.ark-page-header {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-4);
}

.ark-page-header__copy {
    min-width: 0;
}

.ark-page-header__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ark-space-2);
}

@media (min-width: 600px) {
    .ark-page-header {
        flex-direction: row;
        align-items: flex-start;
        justify-content: space-between;
    }

    .ark-page-header__actions {
        flex: 0 0 auto;
        justify-content: flex-end;
    }
}
</style>
