<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

// お試し環境の開発用メニュー（Task 11-36）。サーバー側で local / staging かつ有効時のみ表示。
defineProps<{
    appName: string;
    environment: string;
    quickLogins: string[];
    hpDesigns: { name: string; url: string; external: boolean }[];
}>();

const T = MESSAGES.customerUi.devMenu;

// 「〇〇としてログイン」ボタン。キーはサーバーの DevMenuController::quickLoginAccounts と一致させる。
const loginHref = (key: string): string => `/login?as=${encodeURIComponent(key)}`;
const CUSTOMER_LOGINS = [
    { key: 'customerA', label: T.loginAsCustomerA },
    { key: 'customerB', label: T.loginAsCustomerB },
    { key: 'customerC', label: T.loginAsCustomerC },
];
</script>

<template>
    <v-app>
        <Head :title="`${T.title} | ${appName}`" />

        <v-main class="dev-menu">
            <div class="dev-menu__inner">
                <header class="dev-menu__header">
                    <v-chip color="warning" variant="flat" size="small">{{ T.badge }}（{{ environment }}）</v-chip>
                    <h1 class="dev-menu__title">{{ appName }} {{ T.title }}</h1>
                    <p class="dev-menu__lead">{{ T.lead }}</p>
                </header>

                <section class="dev-menu__section">
                    <h2 class="dev-menu__heading">{{ T.customerHeading }}</h2>
                    <v-card variant="outlined" class="dev-menu__card">
                        <v-btn color="primary" variant="flat" size="large" block href="/booking">{{ T.booking }}</v-btn>
                        <p class="dev-menu__note">{{ T.bookingNote }}</p>
                        <p class="dev-menu__unavailable">{{ T.cardPaymentUnavailable }}</p>
                    </v-card>
                    <v-card variant="outlined" class="dev-menu__card">
                        <v-btn
                            v-for="c in CUSTOMER_LOGINS.filter((x) => quickLogins.includes(x.key))"
                            :key="c.key"
                            color="primary"
                            variant="outlined"
                            size="large"
                            block
                            :href="loginHref(c.key)"
                        >
                            {{ c.label }}
                        </v-btn>
                        <p class="dev-menu__note">{{ T.quickLoginNote }}</p>
                        <p class="dev-menu__unavailable">{{ T.googleUnavailable }}</p>
                        <p class="dev-menu__unavailable">{{ T.resetUnavailable }}</p>
                    </v-card>
                    <v-card variant="outlined" class="dev-menu__card">
                        <v-btn variant="tonal" size="large" block href="/register">{{ T.register }}</v-btn>
                        <p class="dev-menu__unavailable">{{ T.registerNote }}</p>
                    </v-card>
                </section>

                <section class="dev-menu__section">
                    <h2 class="dev-menu__heading">{{ T.storeHeading }}</h2>
                    <v-card variant="outlined" class="dev-menu__card">
                        <v-btn v-if="quickLogins.includes('admin')" color="secondary" variant="flat" size="large" block :href="loginHref('admin')">{{ T.loginAsAdmin }}</v-btn>
                        <v-btn v-if="quickLogins.includes('staff')" color="secondary" variant="outlined" size="large" block :href="loginHref('staff')">{{ T.loginAsStaff }}</v-btn>
                        <p class="dev-menu__note">{{ T.adminNote }}</p>
                        <p class="dev-menu__note">{{ T.quickLoginNote }}</p>
                    </v-card>
                </section>

                <section class="dev-menu__section">
                    <h2 class="dev-menu__heading">{{ T.hpDesignsHeading }}</h2>
                    <v-card variant="outlined" class="dev-menu__card">
                        <template v-if="hpDesigns.length > 0">
                            <v-btn
                                v-for="d in hpDesigns"
                                :key="d.url"
                                color="primary"
                                variant="tonal"
                                size="large"
                                block
                                :href="d.url"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ fillMessage(T.hpDesignOpen, { name: d.name }) }}
                            </v-btn>
                            <p class="dev-menu__note">{{ T.hpDesignsNote }}</p>
                        </template>
                        <p v-else class="dev-menu__note">{{ T.hpDesignsEmpty }}</p>
                    </v-card>
                </section>

                <section class="dev-menu__section">
                    <h2 class="dev-menu__heading">{{ T.cardHeading }}</h2>
                    <p>{{ T.cardNumber }}</p>
                    <p class="dev-menu__note">{{ T.cardNote }}</p>
                </section>
            </div>
        </v-main>
    </v-app>
</template>

<style scoped>
.dev-menu {
    min-height: 100vh;
    background: rgb(var(--v-theme-background));
    padding: var(--ark-space-5);
}

.dev-menu__inner {
    width: min(100%, 36rem);
    margin: 0 auto;
    display: grid;
    gap: var(--ark-space-5);
}

.dev-menu__header {
    display: grid;
    gap: var(--ark-space-2);
    justify-items: start;
}

.dev-menu__title {
    font-size: 1.5rem;
    font-weight: 700;
}

.dev-menu__lead,
.dev-menu__note {
    color: rgba(var(--v-theme-on-surface), 0.7);
    font-size: 0.875rem;
}

.dev-menu__section {
    display: grid;
    gap: var(--ark-space-3);
}

.dev-menu__heading {
    font-size: 1.125rem;
    font-weight: 700;
}

.dev-menu__unavailable {
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.55);
}

.dev-menu__card {
    padding: var(--ark-space-4);
    display: grid;
    gap: var(--ark-space-2);
}
</style>
