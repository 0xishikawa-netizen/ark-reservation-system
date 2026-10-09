<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';

const page = usePage();

const logout = (): void => {
    router.post('/logout');
};

interface NavItem {
    label: string;
    icon: string;
    href: string;
    match: string;
}

const navItems: NavItem[] = [
    { label: MESSAGES.customerUi.customerLayout.home, icon: 'mdi-home', href: '/', match: '/' },
    { label: MESSAGES.customerUi.customerLayout.reserve, icon: 'mdi-calendar-plus', href: '/reserve', match: '/reserve' },
    { label: MESSAGES.customerUi.customerLayout.tickets, icon: 'mdi-ticket-confirmation', href: '/mypage/tickets', match: '/mypage/tickets' },
    { label: MESSAGES.customerUi.customerLayout.membership, icon: 'mdi-card-account-details', href: '/mypage/membership', match: '/mypage/membership' },
    { label: MESSAGES.customerUi.customerLayout.payments, icon: 'mdi-receipt-text', href: '/mypage/payments', match: '/mypage/payments' },
    { label: MESSAGES.customerUi.customerLayout.account, icon: 'mdi-account', href: '/mypage/profile', match: '/mypage/profile' },
];

const currentPath = computed<string>(() => {
    const url = page.url ?? '/';

    return url.split('?')[0] ?? '/';
});

const activeIndex = computed<number>(() =>
    navItems.findIndex((item) =>
        item.match === '/' ? currentPath.value === '/' : currentPath.value.startsWith(item.match),
    ),
);

const go = (href: string): void => {
    if (currentPath.value !== href) {
        router.visit(href);
    }
};
</script>

<template>
    <v-app>
        <v-app-bar color="surface" density="comfortable" border="b">
            <v-app-bar-title class="text-primary font-weight-bold">
                {{ page.props.name }}
            </v-app-bar-title>

            <template #append>
                <span
                    v-if="page.props.auth.user"
                    class="d-none d-sm-inline mr-2 text-body-2 text-medium-emphasis"
                >
                    {{ page.props.auth.user.name }}
                </span>
                <v-btn
                    v-if="page.props.auth.user"
                    variant="text"
                    color="primary"
                    @click="logout"
                >
                    {{ MESSAGES.customerUi.customerLayout.logout }}
                </v-btn>
            </template>
        </v-app-bar>

        <v-main class="bg-background pb-16">
            <v-container class="customer-content px-4 py-8">
                <v-alert
                    v-if="page.props.flash.success"
                    type="success"
                    class="mb-4"
                    density="comfortable"
                >
                    {{ page.props.flash.success }}
                </v-alert>
                <v-alert
                    v-if="page.props.flash.error"
                    type="error"
                    class="mb-4"
                    density="comfortable"
                >
                    {{ page.props.flash.error }}
                </v-alert>
                <v-alert
                    v-if="page.props.flash.info"
                    type="info"
                    class="mb-4"
                    density="comfortable"
                >
                    {{ page.props.flash.info }}
                </v-alert>

                <slot />
            </v-container>
        </v-main>

        <v-bottom-navigation
            :model-value="activeIndex"
            color="primary"
            bg-color="surface"
            height="64"
            grow
            class="customer-bottom-nav"
            :aria-label="MESSAGES.customerUi.customerLayout.menu"
        >
            <v-btn
                v-for="(item, index) in navItems"
                :key="item.href"
                :aria-label="item.label"
                :aria-current="activeIndex === index ? 'page' : undefined"
                @click="go(item.href)"
            >
                <v-icon>{{ item.icon }}</v-icon>
                <span class="text-caption">{{ item.label }}</span>
            </v-btn>
        </v-bottom-navigation>
    </v-app>
</template>

<style scoped>
.customer-content {
    max-width: 48rem;
}

.customer-bottom-nav {
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.customer-bottom-nav :deep(.v-btn) {
    position: relative;
    min-width: 44px;
    min-height: 56px;
    padding-inline: 2px;
}

.customer-bottom-nav :deep(.v-btn--active)::after {
    position: absolute;
    top: 0;
    left: 22%;
    width: 56%;
    height: 3px;
    border-radius: 0 0 var(--ark-radius-sm) var(--ark-radius-sm);
    background: rgb(var(--v-theme-primary));
    content: '';
}

.customer-bottom-nav :deep(.v-btn__content) {
    gap: 1px;
}
</style>
