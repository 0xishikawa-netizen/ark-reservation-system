<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

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
    { label: 'ホーム', icon: 'mdi-home', href: '/', match: '/' },
    { label: '予約', icon: 'mdi-calendar-plus', href: '/reserve', match: '/reserve' },
    { label: '回数券', icon: 'mdi-ticket-confirmation', href: '/mypage/tickets', match: '/mypage/tickets' },
    { label: '会員', icon: 'mdi-card-account-details', href: '/mypage/membership', match: '/mypage/membership' },
    { label: '支払い', icon: 'mdi-receipt-text', href: '/mypage/payments', match: '/mypage/payments' },
    { label: 'アカウント', icon: 'mdi-account', href: '/mypage/profile', match: '/mypage/profile' },
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
        <v-app-bar color="primary" density="comfortable">
            <v-app-bar-title>{{ page.props.name }}</v-app-bar-title>

            <template #append>
                <span v-if="page.props.auth.user" class="d-none d-sm-inline mr-3">
                    {{ page.props.auth.user.name }}
                </span>
                <v-btn v-if="page.props.auth.user" variant="text" @click="logout">
                    ログアウト
                </v-btn>
            </template>
        </v-app-bar>

        <v-main class="bg-grey-lighten-4 pb-16">
            <v-container class="customer-content px-4 py-6">
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

                <slot />
            </v-container>
        </v-main>

        <v-bottom-navigation
            :model-value="activeIndex"
            color="primary"
            grow
            aria-label="顧客メニュー"
        >
            <v-btn
                v-for="item in navItems"
                :key="item.href"
                :aria-label="item.label"
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
</style>
