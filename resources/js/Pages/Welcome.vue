<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    appName: string;
}>();
</script>

<template>
    <v-app>
        <Head :title="appName" />

        <v-main class="welcome">
            <v-card class="welcome-card" elevation="2">
                <v-card-title>{{ appName }}</v-card-title>
                <v-card-text>Inertia + Vue 3 + TypeScript + Vuetify 3</v-card-text>
                <v-card-actions class="justify-center">
                    <v-btn color="primary" variant="flat">Vuetify 3</v-btn>
                </v-card-actions>
            </v-card>
        </v-main>
    </v-app>
</template>

<style scoped>
.welcome {
    align-items: center;
    display: flex;
    justify-content: center;
    padding: 1.5rem;
    text-align: center;
}

.welcome-card {
    width: min(100%, 32rem);
}
</style>
