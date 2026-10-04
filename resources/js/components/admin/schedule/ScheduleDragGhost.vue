<script setup lang="ts">
import { minuteToLabel } from "@/components/admin/schedule/scheduleFormat";

/** ドラッグ中にマウスへ追従するゴーストカード（§16）。 */
defineProps<{
    name: string;
    x: number;
    y: number;
    startMin: number;
    durationMin: number;
}>();
</script>

<template>
    <div class="drag-ghost" :style="{ left: `${x}px`, top: `${y}px` }" aria-hidden="true">
        <div class="drag-ghost__name">{{ name }}</div>
        <div class="drag-ghost__time">
            {{ minuteToLabel(startMin) }}–{{ minuteToLabel(startMin + durationMin) }}
        </div>
    </div>
</template>

<style scoped>
.drag-ghost {
    position: fixed;
    z-index: 2700;
    transform: translate(14px, -50%);
    padding: var(--ark-space-2) var(--ark-space-3);
    background: rgb(var(--v-theme-primary));
    color: #fff;
    border-radius: var(--ark-radius);
    box-shadow: 0 10px 28px rgb(18 25 60 / 32%);
    pointer-events: none;
    white-space: nowrap;
}

.drag-ghost__name {
    font-size: 0.8125rem;
    font-weight: 800;
}

.drag-ghost__time {
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
    opacity: 0.9;
}
</style>
