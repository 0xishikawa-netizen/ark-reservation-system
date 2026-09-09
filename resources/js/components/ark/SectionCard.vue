<script setup lang="ts">
defineProps<{
    title?: string;
    subtitle?: string;
}>();
</script>

<template>
    <v-card>
        <v-card-item v-if="title || subtitle || $slots.append" class="ark-section-card__header">
            <div class="d-flex align-start justify-space-between ga-3">
                <div class="min-width-0">
                    <div v-if="title" class="ark-section-card__title text-subtitle-1">
                        {{ title }}
                    </div>
                    <div v-if="subtitle" class="text-body-2 text-medium-emphasis mt-1">
                        {{ subtitle }}
                    </div>
                </div>

                <div v-if="$slots.append" class="ark-section-card__append">
                    <slot name="append" />
                </div>
            </div>
        </v-card-item>

        <v-card-text>
            <slot />
        </v-card-text>
    </v-card>
</template>

<style scoped>
.ark-section-card__header {
    padding-bottom: var(--ark-space-2);
}

.ark-section-card__title {
    font-weight: 600;
    line-height: 1.5;
}

.ark-section-card__append {
    flex: 0 0 auto;
}

.min-width-0 {
    min-width: 0;
}
</style>
