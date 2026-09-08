<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface NavigationItem {
    title: string;
    href: string;
    disabled: boolean;
}

const page = usePage();

const navigationItems = computed<NavigationItem[]>(() => {
    const can = page.props.auth.can;

    return [
        {
            title: 'ダッシュボード',
            href: '/admin',
            disabled: false,
        },
        ...(can.staffManage
            ? [{ title: 'スタッフ', href: '/admin/staff', disabled: false }]
            : []),
        ...(can.shiftsManage
            ? [{ title: '勤務枠', href: '/admin/staff-shifts', disabled: false }]
            : []),
        ...(can.servicesManage
            ? [{ title: 'サービス', href: '/admin/services', disabled: false }]
            : []),
        ...(can.boothsManage
            ? [{ title: 'ブース', href: '/admin/booths', disabled: false }]
            : []),
        ...(can.ticketProductsManage
            ? [
                  {
                      title: '回数券商品',
                      href: '/admin/ticket-products',
                      disabled: false,
                  },
              ]
            : []),
        ...(can.membershipManage
            ? [
                  {
                      title: '会員プラン',
                      href: '/admin/membership-plans',
                      disabled: false,
                  },
              ]
            : []),
        ...(can.customersView
            ? [{ title: '顧客', href: '/admin/customers', disabled: false }]
            : []),
        ...(can.reservationsView
            ? [
                  { title: '予約', href: '/admin/reservations', disabled: false },
                  { title: '予約台帳', href: '/admin/schedule', disabled: false },
                  { title: '決済', href: '/admin/payments', disabled: false },
              ]
            : []),
        ...(can.failedJobsView
            ? [
                  {
                      title: '失敗ジョブ',
                      href: '/admin/system/failed-jobs',
                      disabled: false,
                  },
              ]
            : []),
        ...(can.auditLogsView
            ? [
                  {
                      title: '監査ログ',
                      href: '/admin/system/audit-logs',
                      disabled: true,
                  },
              ]
            : []),
        ...(can.ticketPolicyManage
            ? [
                  {
                      title: '回数券運用設定',
                      href: '/admin/settings/tickets',
                      disabled: false,
                  },
              ]
            : []),
    ];
});

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
        <v-navigation-drawer permanent width="256">
            <v-list-item class="py-4" :title="page.props.name" subtitle="管理画面" />
            <v-divider />
            <v-list nav class="py-3" aria-label="管理メニュー">
                <v-list-item
                    v-for="item in navigationItems"
                    :key="item.title"
                    :title="item.title"
                    :disabled="item.disabled"
                    @click="visit(item)"
                />
            </v-list>
        </v-navigation-drawer>

        <v-app-bar color="white" elevation="1">
            <v-app-bar-title>管理画面</v-app-bar-title>
            <template #append>
                <span v-if="page.props.auth.user" class="mr-4">
                    {{ page.props.auth.user.name }}
                </span>
                <v-btn variant="text" @click="logout">ログアウト</v-btn>
            </template>
        </v-app-bar>

        <v-main class="bg-grey-lighten-4">
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
