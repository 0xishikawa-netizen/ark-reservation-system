<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <v-app>
        <v-app-bar color="surface" density="comfortable" border="b">
            <v-app-bar-title class="guest-brand text-primary font-weight-bold">
                {{ page.props.name }}
            </v-app-bar-title>
        </v-app-bar>

        <v-main class="bg-background">
            <v-container class="guest-content px-4 py-6 py-sm-8">
                <v-alert
                    v-if="page.props.flash.success"
                    type="success"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ page.props.flash.success }}
                </v-alert>
                <v-alert
                    v-if="page.props.flash.error"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ page.props.flash.error }}
                </v-alert>
                <v-alert
                    v-if="page.props.flash.info"
                    type="info"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ page.props.flash.info }}
                </v-alert>

                <slot />

                <p class="guest-legal">
                    © {{ new Date().getFullYear() }} {{ page.props.name }}
                </p>
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.guest-content {
    max-width: 40rem;
}

.guest-brand {
    font-size: 1rem;
}

.guest-legal {
    margin: var(--ark-space-5) 0 0;
    color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
    font-size: 0.75rem;
    text-align: center;
}
</style>
