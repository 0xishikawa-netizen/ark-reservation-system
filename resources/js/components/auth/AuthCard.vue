<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';

defineProps<{
    title: string;
    subtitle?: string;
}>();

const page = usePage();
const appName = (page.props.name as string) ?? 'ARK Conditioning';
</script>

<template>
    <v-app>
        <Head :title="title" />
        <v-main class="ark-auth">
            <div class="ark-auth__inner">
                <div class="ark-auth__brand">
                    <span class="ark-auth__mark" aria-hidden="true">
                        <svg viewBox="0 0 40 40" width="36" height="36">
                            <defs>
                                <linearGradient id="arkMark" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#0087C5" />
                                    <stop offset="0.55" stop-color="#1B4B9C" />
                                    <stop offset="1" stop-color="#12193C" />
                                </linearGradient>
                            </defs>
                            <path
                                d="M20 3 L36 37 H27.5 L20 19.5 L12.5 37 H4 Z"
                                fill="url(#arkMark)"
                            />
                        </svg>
                    </span>
                    <span class="ark-auth__name">{{ appName }}</span>
                </div>

                <v-card class="ark-auth__card" rounded="lg">
                    <v-card-item>
                        <v-card-title class="ark-auth__title">{{ title }}</v-card-title>
                        <v-card-subtitle v-if="subtitle" class="ark-auth__subtitle">
                            {{ subtitle }}
                        </v-card-subtitle>
                    </v-card-item>
                    <v-card-text>
                        <slot />
                    </v-card-text>
                    <v-card-actions v-if="$slots.footer" class="ark-auth__footer">
                        <slot name="footer" />
                    </v-card-actions>
                </v-card>

                <p class="ark-auth__legal">
                    © {{ new Date().getFullYear() }} {{ appName }}
                </p>
            </div>
        </v-main>
    </v-app>
</template>

<style scoped>
.ark-auth {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background: rgb(var(--v-theme-background));
    padding: var(--ark-space-4);
}

.ark-auth__inner {
    width: 100%;
    max-width: 25rem;
}

.ark-auth__brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--ark-space-3);
    margin-bottom: var(--ark-space-5);
}

.ark-auth__name {
    font-size: 1.125rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: rgb(var(--v-theme-primary));
}

.ark-auth__card {
    border: 1px solid #d9dee5;
    box-shadow: var(--ark-shadow-2);
}

.ark-auth__title {
    font-size: 1.25rem;
    font-weight: 700;
}

.ark-auth__subtitle {
    white-space: normal;
    line-height: 1.6;
    opacity: 0.8;
}

.ark-auth__footer {
    justify-content: center;
    padding-block: var(--ark-space-4);
}

.ark-auth__legal {
    margin-top: var(--ark-space-5);
    text-align: center;
    font-size: 0.75rem;
    color: rgb(var(--v-theme-secondary));
}

:deep(.ark-auth-divider) {
    display: flex;
    align-items: center;
    gap: var(--ark-space-3);
    color: rgb(var(--v-theme-secondary));
    font-size: 0.8125rem;
}

:deep(.ark-auth-divider)::before,
:deep(.ark-auth-divider)::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #d9dee5;
}

:deep(.ark-google-btn) {
    border-color: #d9dee5;
    text-transform: none;
    font-weight: 600;
    letter-spacing: 0;
}

:deep(.ark-google-btn__icon) {
    display: inline-flex;
    margin-right: var(--ark-space-2);
}
</style>
