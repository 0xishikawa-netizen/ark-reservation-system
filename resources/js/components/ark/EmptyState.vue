<script setup lang="ts">
withDefaults(defineProps<{
    icon?: string;
    title: string;
    description?: string;
}>(), {
    icon: 'mdi-inbox-outline',
});
</script>

<template>
    <div class="text-center py-10 px-4">
        <v-icon :icon="icon" size="40" class="text-medium-emphasis mb-3" />
        <div class="text-subtitle-1 font-weight-medium">{{ title }}</div>
        <p v-if="description" class="text-body-2 text-medium-emphasis mt-1 mb-0">
            {{ description }}
        </p>
        <div v-if="$slots.action" class="mt-5">
            <slot name="action" />
        </div>
    </div>
</template>
