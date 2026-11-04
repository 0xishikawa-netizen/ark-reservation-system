<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';

// お試し環境の開発用メニュー（Task 11-36）。サーバー側で local / staging かつ有効時のみ表示。
defineProps<{
    appName: string;
    environment: string;
    accounts: {
        admin: string;
        staff: string;
        customer: string;
    };
}>();

const T = MESSAGES.customerUi.devMenu;
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
                    </v-card>
                    <v-card variant="outlined" class="dev-menu__card">
                        <v-btn color="primary" variant="outlined" size="large" block href="/login">{{ T.login }}</v-btn>
                        <p class="dev-menu__note">{{ T.loginNote }}</p>
                        <v-btn variant="text" size="small" href="/register">{{ T.register }}</v-btn>
                    </v-card>
                </section>

                <section class="dev-menu__section">
                    <h2 class="dev-menu__heading">{{ T.storeHeading }}</h2>
                    <v-card variant="outlined" class="dev-menu__card">
                        <v-btn color="secondary" variant="flat" size="large" block href="/admin">{{ T.admin }}</v-btn>
                        <p class="dev-menu__note">{{ T.adminNote }}</p>
                    </v-card>
                </section>

                <section class="dev-menu__section">
                    <h2 class="dev-menu__heading">{{ T.accountsHeading }}</h2>
                    <v-table density="compact">
                        <tbody>
                            <tr>
                                <th>{{ T.accountAdmin }}</th>
                                <td><code>{{ accounts.admin }}</code></td>
                            </tr>
                            <tr>
                                <th>{{ T.accountStaff }}</th>
                                <td><code>{{ accounts.staff }}</code></td>
                            </tr>
                            <tr>
                                <th>{{ T.accountCustomer }}</th>
                                <td><code>{{ accounts.customer }}</code></td>
                            </tr>
                        </tbody>
                    </v-table>
                    <p class="dev-menu__note">{{ T.passwordNote }}</p>
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

.dev-menu__card {
    padding: var(--ark-space-4);
    display: grid;
    gap: var(--ark-space-2);
}
</style>
