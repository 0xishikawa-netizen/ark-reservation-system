<script setup lang="ts">
import { statusColor } from '@/design/tokens';

withDefaults(defineProps<{
    status: string;
    label: string;
    size?: string;
}>(), {
    size: 'small',
});
</script>

<template>
    <v-chip :color="statusColor(status)" :size="size" variant="tonal">
        {{ label }}
    </v-chip>
</template>
