<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const navigationGroupTitles = [
    'ホーム',
    '予約',
    '顧客',
    '回数券・会員',
    '支払い',
    'マスタ',
    'システム',
] as const;

type NavigationGroupTitle = (typeof navigationGroupTitles)[number];

interface NavigationItem {
    title: string;
    href: string;
    disabled: boolean;
    icon: string;
    group: NavigationGroupTitle;
}

interface NavigationGroup {
    title: NavigationGroupTitle;
    items: NavigationItem[];
}

const page = usePage();

const navigationItems = computed<NavigationItem[]>(() => {
    const can = page.props.auth.can;

    return [
        {
            title: 'ダッシュボード',
            href: '/admin',
            disabled: false,
            icon: 'mdi-view-dashboard-outline',
            group: 'ホーム',
        },
        ...(can.staffManage
            ? [
                  {
                      title: 'スタッフ',
                      href: '/admin/staff',
                      disabled: false,
                      icon: 'mdi-account-group-outline',
                      group: 'マスタ' as const,
                  },
              ]
            : []),
        ...(can.shiftsManage
            ? [
                  {
                      title: '勤務枠',
                      href: '/admin/staff-shifts',
                      disabled: false,
                      icon: 'mdi-calendar-clock-outline',
                      group: 'マスタ' as const,
                  },
              ]
            : []),
        ...(can.servicesManage
            ? [
                  {
                      title: 'サービス',
                      href: '/admin/services',
                      disabled: false,
                      icon: 'mdi-clipboard-text-outline',
                      group: 'マスタ' as const,
                  },
              ]
            : []),
        ...(can.boothsManage
            ? [
                  {
                      title: 'ブース',
                      href: '/admin/booths',
                      disabled: false,
                      icon: 'mdi-door-open',
                      group: 'マスタ' as const,
                  },
              ]
            : []),
        ...(can.ticketProductsManage
            ? [
                  {
                      title: '回数券商品',
                      href: '/admin/ticket-products',
                      disabled: false,
                      icon: 'mdi-ticket-confirmation-outline',
                      group: '回数券・会員' as const,
                  },
              ]
            : []),
        ...(can.membershipManage
            ? [
                  {
                      title: '会員プラン',
                      href: '/admin/membership-plans',
                      disabled: false,
                      icon: 'mdi-card-account-details-outline',
                      group: '回数券・会員' as const,
                  },
              ]
            : []),
        ...(can.customersView
            ? [
                  {
                      title: '顧客',
                      href: '/admin/customers',
                      disabled: false,
                      icon: 'mdi-account-multiple-outline',
                      group: '顧客' as const,
                  },
              ]
            : []),
        ...(can.reservationsView
            ? [
                  {
                      title: '予約',
                      href: '/admin/reservations',
                      disabled: false,
                      icon: 'mdi-calendar-check-outline',
                      group: '予約' as const,
                  },
                  {
                      title: '予約台帳',
                      href: '/admin/schedule',
                      disabled: false,
                      icon: 'mdi-calendar-month-outline',
                      group: '予約' as const,
                  },
                  {
                      title: '決済',
                      href: '/admin/payments',
                      disabled: false,
                      icon: 'mdi-credit-card-outline',
                      group: '支払い' as const,
                  },
              ]
            : []),
        ...(can.failedJobsView
            ? [
                  {
                      title: 'システム状態',
                      href: '/admin/system/status',
                      disabled: false,
                      icon: 'mdi-monitor-dashboard',
                      group: 'システム' as const,
                  },
                  {
                      title: '失敗ジョブ',
                      href: '/admin/system/failed-jobs',
                      disabled: false,
                      icon: 'mdi-alert-circle-outline',
                      group: 'システム' as const,
                  },
              ]
            : []),
        ...(can.auditLogsView
            ? [
                  {
                      title: '監査ログ',
                      href: '/admin/system/audit-logs',
                      disabled: false,
                      icon: 'mdi-clipboard-text-clock-outline',
                      group: 'システム' as const,
                  },
              ]
            : []),
        ...(can.integrationsView
            ? [
                  {
                      title: '外部予約連携',
                      href: '/admin/integrations/reservations',
                      disabled: false,
                      icon: 'mdi-sync',
                      group: 'システム' as const,
                  },
              ]
            : []),
        ...(can.ticketPolicyManage
            ? [
                  {
                      title: '回数券運用設定',
                      href: '/admin/settings/tickets',
                      disabled: false,
                      icon: 'mdi-tune-variant',
                      group: 'マスタ' as const,
                  },
              ]
            : []),
    ];
});

const navigationGroups = computed<NavigationGroup[]>(() =>
    navigationGroupTitles
        .map((title) => ({
            title,
            items: navigationItems.value.filter((item) => item.group === title),
        }))
        .filter((group) => group.items.length > 0),
);

const currentPath = computed(() => page.url.split(/[?#]/, 1)[0] || '/');

const isActive = (item: NavigationItem): boolean => {
    if (item.href === '/admin') {
        return currentPath.value === item.href;
    }

    return (
        currentPath.value === item.href ||
        currentPath.value.startsWith(`${item.href}/`)
    );
};

const visit = (item: NavigationItem): void => {
    if (!item.disabled) {
        router.visit(item.href);
    }
};

const logout = (): void => {
    router.post('/logout');
};
</script>

<template>
    <v-app>
        <v-navigation-drawer permanent width="256" color="surface" border="e">
            <v-list-item class="py-4" :title="page.props.name" subtitle="管理画面" />
            <v-divider />
            <v-list nav class="py-3" aria-label="管理メニュー">
                <template v-for="group in navigationGroups" :key="group.title">
                    <v-list-subheader class="text-overline">
                        {{ group.title }}
                    </v-list-subheader>
                    <v-list-item
                        v-for="item in group.items"
                        :key="item.title"
                        :title="item.title"
                        :disabled="item.disabled"
                        :active="isActive(item)"
                        :aria-current="isActive(item) ? 'page' : undefined"
                        color="primary"
                        rounded="lg"
                        @click="visit(item)"
                    >
                        <template #prepend>
                            <v-icon :icon="item.icon" aria-hidden="true" />
                        </template>
                    </v-list-item>
                </template>
            </v-list>
        </v-navigation-drawer>

        <v-app-bar color="surface" border="b">
            <v-app-bar-title class="text-primary font-weight-bold">
                {{ page.props.name }}
            </v-app-bar-title>
            <template #append>
                <span v-if="page.props.auth.user" class="mr-4">
                    {{ page.props.auth.user.name }}
                </span>
                <v-btn variant="text" @click="logout">ログアウト</v-btn>
            </template>
        </v-app-bar>

        <v-main class="bg-background">
            <v-container fluid class="pa-6">
                <v-alert
                    v-if="page.props.flash.success"
                    type="success"
                    class="mb-4"
                >
                    {{ page.props.flash.success }}
                </v-alert>
                <v-alert
                    v-if="page.props.flash.error"
                    type="error"
                    class="mb-4"
                >
                    {{ page.props.flash.error }}
                </v-alert>

                <slot />
            </v-container>
        </v-main>
    </v-app>
</template>
