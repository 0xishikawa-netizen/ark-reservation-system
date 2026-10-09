<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';

defineProps<{
    appName: string;
}>();
</script>

<template>
    <v-app>
        <Head :title="appName" />

        <v-main class="welcome">
            <div class="welcome__inner">
                <span class="welcome__mark" aria-hidden="true">
                    <svg viewBox="0 0 44 44" width="44" height="44">
                        <defs>
                            <linearGradient id="welcomeMark" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0" stop-color="#0087C5" />
                                <stop offset="0.55" stop-color="#1B4B9C" />
                                <stop offset="1" stop-color="#12193C" />
                            </linearGradient>
                        </defs>
                        <path d="M22 4 L40 40 H30.5 L22 21 L13.5 40 H4 Z" fill="url(#welcomeMark)" />
                    </svg>
                </span>

                <h1 class="welcome__title">{{ appName }}</h1>
                <p class="welcome__lead">
                    {{ MESSAGES.customerUi.welcome.leadFirst }}<br />
                    {{ MESSAGES.customerUi.welcome.leadSecond }}
                </p>

                <div class="welcome__actions">
                    <v-btn color="primary" variant="flat" size="large" href="/login">
                        {{ MESSAGES.customerUi.welcome.login }}
                    </v-btn>
                    <v-btn color="primary" variant="outlined" size="large" href="/register">
                        {{ MESSAGES.customerUi.welcome.register }}
                    </v-btn>
                </div>
            </div>
        </v-main>
    </v-app>
</template>

<style scoped>
.welcome {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background: rgb(var(--v-theme-background));
    padding: var(--ark-space-5);
}

.welcome__inner {
    width: min(100%, 30rem);
    text-align: center;
}

.welcome__mark {
    display: inline-flex;
    margin-bottom: var(--ark-space-4);
}

.welcome__title {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: rgb(var(--v-theme-primary));
    margin: 0 0 var(--ark-space-3);
}

.welcome__lead {
    line-height: 1.8;
    color: rgb(var(--v-theme-on-background));
    opacity: 0.75;
    margin: 0 0 var(--ark-space-6);
}

.welcome__actions {
    display: flex;
    gap: var(--ark-space-3);
    justify-content: center;
    flex-wrap: wrap;
}
</style>
