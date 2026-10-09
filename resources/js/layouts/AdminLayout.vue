<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useSessionKeepAlive } from '@/composables/sessionKeepAlive';
import { MESSAGES } from '@/constants/messages';
import { reportNavigationItems } from './reportNavigation';
import { settingsNavigationItems, settingsSections } from './settingsNavigation';

/** 画面右上の時計を更新する間隔（ミリ秒）。 */
const CLOCK_INTERVAL_MS = 1000;

/** ヘッダーのグループ名・項目名。 */
const GROUP = MESSAGES.customerUi.navigation.groups;
const ITEM = MESSAGES.customerUi.navigation.items;

const navigationGroupTitles = [
    // 店舗業務のメイン画面。ヘッダー左端（ダッシュボードより前）に単独タブで出す。
    GROUP.board,
    GROUP.home,
    GROUP.reservation,
    GROUP.customer,
    GROUP.payment,
    GROUP.reports,
    GROUP.settings,
    GROUP.system,
] as const;

type NavigationGroupTitle = (typeof navigationGroupTitles)[number];

interface NavigationItem {
    title: string;
    href: string;
    disabled: boolean;
    icon: string;
    group: NavigationGroupTitle;
    /** 「設定」など項目が多いグループ内を責務ごとに束ねる小見出し（任意）。 */
    subgroup?: string;
}

interface NavigationGroup {
    title: NavigationGroupTitle;
    items: NavigationItem[];
}

const page = usePage();
const mobileNavOpen = ref(false);

const snackbar = ref<{ show: boolean; text: string; color: string }>({
    show: false,
    text: '',
    color: 'success',
});

watch(
    () => [page.props.flash.success, page.props.flash.error, page.props.flash.info],
    ([success, error, info]) => {
        const message = success ?? error ?? info;

        if (!message) {
            return;
        }

        snackbar.value = {
            show: true,
            text: message,
            color: error ? 'error' : success ? 'success' : 'info',
        };
    },
    { immediate: true },
);

const navigationItems = computed<NavigationItem[]>(() => {
    const can = page.props.auth.can;

    return [
        {
            title: ITEM.dashboard,
            href: '/admin',
            disabled: false,
            icon: 'mdi-view-dashboard-outline',
            group: GROUP.home,
        },
        ...(can.reservationsView
            ? [{ title: ITEM.schedule, href: '/admin/schedule', disabled: false, icon: 'mdi-calendar-month-outline', group: GROUP.board }]
            : []),
        ...reportNavigationItems(can, page.props.auth.reportRoutes),
        ...settingsNavigationItems(can),
        ...(can.checkoutsManage
            ? [{ title: ITEM.checkouts, href: '/admin/checkouts', disabled: false, icon: 'mdi-cash-register', group: GROUP.payment }]
            : []),
        ...(can.customersView
            ? [{ title: ITEM.customers, href: '/admin/customers', disabled: false, icon: 'mdi-account-multiple-outline', group: GROUP.customer }]
            : []),
        ...(can.reservationsView
            ? [
                  { title: ITEM.reservations, href: '/admin/reservations', disabled: false, icon: 'mdi-format-list-bulleted', group: GROUP.reservation },
                  { title: ITEM.payments, href: '/admin/payments', disabled: false, icon: 'mdi-credit-card-outline', group: GROUP.payment },
              ]
            : []),
        ...(can.failedJobsView
            ? [
                  { title: ITEM.systemStatus, href: '/admin/system/status', disabled: false, icon: 'mdi-monitor-dashboard', group: GROUP.system },
                  { title: ITEM.failedJobs, href: '/admin/system/failed-jobs', disabled: false, icon: 'mdi-alert-circle-outline', group: GROUP.system },
              ]
            : []),
        ...(can.auditLogsView
            ? [{ title: ITEM.auditLogs, href: '/admin/system/audit-logs', disabled: false, icon: 'mdi-clipboard-text-clock-outline', group: GROUP.system }]
            : []),
        ...(can.integrationsView
            ? [{ title: ITEM.integrations, href: '/admin/integrations/reservations', disabled: false, icon: 'mdi-sync', group: GROUP.system }]
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

interface NavigationSection {
    label: string | null;
    /** カテゴリのアイコン（ネストするカテゴリのみ）。 */
    icon: string | null;
    /** true のとき、カテゴリを第1階層に置き、項目は右側のサブメニューに出す。 */
    nested: boolean;
    items: NavigationItem[];
}

/**
 * ドロップダウン内の項目を責務ごとの小見出し（subgroup）でまとめる。
 * 「設定」は第1階層をカテゴリに統一し、項目が1つのカテゴリも含めて必ずネストする。
 * subgroup を持たない項目は見出しなしのセクションにまとめる。
 */
const groupSections = (items: NavigationItem[]): NavigationSection[] => {
    if (items.length > 0 && items.every((item) => item.group === GROUP.settings)) {
        return settingsSections(items).map((section) => ({
            label: section.title,
            icon: section.icon,
            nested: true,
            items: section.items,
        }));
    }

    const order: (string | null)[] = [];
    const buckets = new Map<string | null, NavigationItem[]>();

    for (const item of items) {
        const key = item.subgroup ?? null;

        if (!buckets.has(key)) {
            buckets.set(key, []);
            order.push(key);
        }

        buckets.get(key)!.push(item);
    }

    return order.map((label) => ({ label, icon: null, nested: false, items: buckets.get(label)! }));
};

const currentPath = computed(() => page.url.split(/[?#]/, 1)[0] || '/');

const matchesPath = (href: string): boolean => {
    if (href === '/admin') {
        return currentPath.value === href;
    }

    return currentPath.value === href || currentPath.value.startsWith(`${href}/`);
};

const isActive = (item: NavigationItem): boolean => {
    if (!matchesPath(item.href)) {
        return false;
    }

    // より具体的（href が長い）な項目が一致していればそちらを優先する。
    // 例: /admin/system/failed-jobs は「失敗ジョブ」だけをアクティブにする。
    const moreSpecific = navigationItems.value.some(
        (other) =>
            other.href !== item.href &&
            other.href.length > item.href.length &&
            matchesPath(other.href),
    );

    return !moreSpecific;
};

const activeGroup = computed<NavigationGroupTitle | null>(() => {
    const hit = navigationItems.value.find((item) => isActive(item));

    return hit?.group ?? null;
});

const visit = (item: NavigationItem): void => {
    if (!item.disabled) {
        router.visit(item.href);
        mobileNavOpen.value = false;
    }
};

const logout = (): void => {
    router.post('/logout');
};

// 開いている間はセッションを維持する。本当に失効した時だけ再ログインの案内を出す（自動ログアウトはしない）。
const { sessionLost } = useSessionKeepAlive();

// 画面右上に現在時刻を表示する（Peak Manager 参考）。
const now = ref(new Date());
let clockTimer: ReturnType<typeof setInterval> | null = null;

const currentTimeLabel = computed(() => {
    const h = String(now.value.getHours()).padStart(2, '0');
    const m = String(now.value.getMinutes()).padStart(2, '0');

    return `${h}:${m}`;
});

onMounted(() => {
    clockTimer = setInterval(() => { now.value = new Date(); }, CLOCK_INTERVAL_MS);
});

onBeforeUnmount(() => {
    if (clockTimer !== null) {
        clearInterval(clockTimer);
    }
});
</script>

<template>
    <v-app>
        <!-- 上：主メニュー（ヘッダー） -->
        <v-app-bar color="surface" flat border="b" height="60">
            <template #prepend>
                <v-btn
                    class="d-md-none"
                    icon="mdi-menu"
                    :aria-label="MESSAGES.customerUi.navigation.openMenu"
                    color="primary"
                    variant="text"
                    @click="mobileNavOpen = true"
                />
                <span class="ark-topbar__brand">
                    <span class="ark-topbar__mark" aria-hidden="true">
                        <svg viewBox="0 0 40 40" width="20" height="20">
                            <defs>
                                <linearGradient id="arkTopMark" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#0087C5" />
                                    <stop offset="0.55" stop-color="rgb(var(--v-theme-primary))" />
                                    <stop offset="1" stop-color="rgb(var(--v-theme-primary-darken-1))" />
                                </linearGradient>
                            </defs>
                            <path d="M20 3 L36 37 H27.5 L20 19.5 L12.5 37 H4 Z" fill="url(#arkTopMark)" />
                        </svg>
                    </span>
                    <span class="ark-topbar__name">{{ page.props.name }}</span>
                </span>
            </template>

            <nav class="ark-topnav d-none d-md-flex" :aria-label="MESSAGES.customerUi.navigation.adminMenu">
                <template v-for="group in navigationGroups" :key="group.title">
                    <button
                        v-if="group.items.length === 1"
                        type="button"
                        class="ark-topnav__tab"
                        :class="{ 'ark-topnav__tab--active': activeGroup === group.title }"
                        :aria-current="activeGroup === group.title ? 'page' : undefined"
                        @click="visit(group.items[0])"
                    >
                        {{ group.items[0].title }}
                    </button>

                    <v-menu
                        v-else
                        open-on-hover
                        :open-delay="0"
                        :close-delay="120"
                        location="bottom start"
                        offset="0"
                        transition="fade-transition"
                        content-class="ark-topnav-menu"
                    >
                        <template #activator="{ props: menuProps }">
                            <button
                                v-bind="menuProps"
                                type="button"
                                class="ark-topnav__tab"
                                :class="{ 'ark-topnav__tab--active': activeGroup === group.title }"
                                :aria-current="activeGroup === group.title ? 'page' : undefined"
                            >
                                <span>{{ group.title }}</span>
                                <v-icon icon="mdi-chevron-down" size="14" class="ark-topnav__caret" />
                            </button>
                        </template>
                        <v-list density="compact" nav slim>
                            <template v-for="section in groupSections(group.items)" :key="section.label ?? '_'">
                                <!-- 「設定」のカテゴリ（例: 店舗設定）は第1階層に並べ、hover／クリック／フォーカスで
                                     横（右側）にフライアウトするサブメニューにする。項目が1つのカテゴリも同じ形にそろえる。 -->
                                <v-menu
                                    v-if="section.nested"
                                    submenu
                                    location="end top"
                                    open-on-hover
                                    :open-delay="0"
                                    :close-delay="120"
                                    content-class="ark-topnav-menu"
                                >
                                    <template #activator="{ props: subProps }">
                                        <v-list-item
                                            v-bind="subProps"
                                            :title="section.label ?? ''"
                                            class="ark-topnav-menu__group-activator"
                                            :active="section.items.some((item) => isActive(item))"
                                            color="primary"
                                            data-testid="settings-category"
                                            @click.stop
                                        >
                                            <template #prepend>
                                                <v-icon :icon="section.icon ?? 'mdi-folder-outline'" size="18" />
                                            </template>
                                            <template #append>
                                                <v-icon icon="mdi-chevron-right" size="16" />
                                            </template>
                                        </v-list-item>
                                    </template>
                                    <v-list density="compact" nav slim>
                                        <v-list-item
                                            v-for="item in section.items"
                                            :key="item.href"
                                            :title="item.title"
                                            :active="isActive(item)"
                                            color="primary"
                                            @click="visit(item)"
                                        >
                                            <template #prepend>
                                                <v-icon :icon="item.icon" size="18" />
                                            </template>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                                <v-list-item
                                    v-for="item in section.items"
                                    v-else
                                    :key="item.href"
                                    :title="item.title"
                                    :active="isActive(item)"
                                    color="primary"
                                    @click="visit(item)"
                                >
                                    <template #prepend>
                                        <v-icon :icon="item.icon" size="18" />
                                    </template>
                                </v-list-item>
                            </template>
                        </v-list>
                    </v-menu>
                </template>
            </nav>

            <template #append>
                <span class="ark-topbar__clock" :aria-label="MESSAGES.customerUi.navigation.currentTime">
                    <v-icon icon="mdi-clock-outline" size="18" />
                    {{ currentTimeLabel }}
                </span>
                <v-divider vertical inset class="mx-3 d-none d-lg-flex" />
                <span
                    v-if="page.props.auth.user"
                    class="text-medium-emphasis d-none d-lg-inline ark-topbar__user"
                >
                    {{ page.props.auth.user.name }}
                </span>
                <v-divider v-if="page.props.auth.user" vertical inset class="mx-3 d-none d-lg-flex" />
                <v-btn
                    variant="tonal"
                    size="small"
                    color="primary"
                    prepend-icon="mdi-logout"
                    class="mr-1"
                    @click="logout"
                >
                    {{ MESSAGES.customerUi.navigation.logout }}
                </v-btn>
            </template>
        </v-app-bar>

        <!-- 狭い画面：全項目のドロワー -->
        <v-navigation-drawer v-model="mobileNavOpen" temporary width="272">
            <v-list-item class="py-3" :title="page.props.name" :subtitle="MESSAGES.customerUi.navigation.adminConsole" />
            <v-divider />
            <v-list nav :aria-label="MESSAGES.customerUi.navigation.adminMenu">
                <template v-for="group in navigationGroups" :key="group.title">
                    <v-list-subheader class="text-overline">{{ group.title }}</v-list-subheader>
                    <template v-for="section in groupSections(group.items)" :key="section.label ?? '_'">
                        <v-list-subheader
                            v-if="section.label"
                            class="ark-mobilenav__subheader"
                        >
                            <v-icon v-if="section.icon" :icon="section.icon" size="14" class="mr-1" />
                            {{ section.label }}
                        </v-list-subheader>
                        <v-list-item
                            v-for="item in section.items"
                            :key="item.href"
                            :title="item.title"
                            :active="isActive(item)"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            color="primary"
                            rounded="lg"
                            @click="visit(item)"
                        >
                            <template #prepend>
                                <v-icon :icon="item.icon" />
                            </template>
                        </v-list-item>
                    </template>
                </template>
            </v-list>
        </v-navigation-drawer>

        <v-main class="bg-background">
            <v-container fluid class="pa-6">
                <v-alert v-if="sessionLost" type="warning" variant="tonal" class="mb-4" role="alert" data-testid="session-lost">
                    {{ MESSAGES.auth.sessionLost }}
                    <template #append>
                        <v-btn href="/login" color="warning" variant="flat" size="small">{{ MESSAGES.auth.sessionRelogin }}</v-btn>
                    </template>
                </v-alert>
                <slot />
            </v-container>
        </v-main>

        <!-- 保存などの結果を、スクロール位置に関わらず必ず目に入る場所（画面下部）に出す。
             §フラッシュメッセージがページ最上部の v-alert だと、長いページの下の方で
             操作した直後は画面外になり気づけないため snackbar に統一。 -->
        <v-snackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            location="bottom"
            timeout="4000"
        >
            {{ snackbar.text }}
        </v-snackbar>
    </v-app>
</template>

<style scoped>
/* ── トップバーのブランド ── */
.ark-topbar__brand {
    display: inline-flex;
    align-items: center;
    gap: var(--ark-space-2);
    margin-inline: var(--ark-space-2) var(--ark-space-4);
}

.ark-topbar__mark {
    display: inline-flex;
}

.ark-topbar__name {
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    white-space: nowrap;
}

.ark-topbar__user {
    white-space: nowrap;
}

.ark-topbar__clock {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: rgba(var(--v-theme-on-surface), 0.7);
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    white-space: nowrap;
}

/* ── トップメニュー（タブ） ── */
.ark-topnav {
    display: flex;
    align-items: stretch;
    gap: var(--ark-space-1);
    height: 60px;
    margin-left: var(--ark-space-2);
    overflow-x: auto;
    scrollbar-width: none;
}

.ark-topnav::-webkit-scrollbar {
    display: none;
}

.ark-topnav__tab {
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    height: 60px;
    padding-inline: var(--ark-space-4);
    border: 0;
    border-bottom: 3px solid transparent;
    background: transparent;
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    cursor: pointer;
    transition: background-color 0.12s ease;
}

/* 1280px 前後の PC 幅では、右側（時計・氏名・ログアウト）とタブ8個が並びきらず、スクロールバーを隠した
   タブ列の外へ「システム」が押し出されて操作できなかった（全面検証 2026-09-27）。1440px 未満はタブの
   左右余白を詰めて全タブを表示する。 */
@media (max-width: 1439.98px) {
    .ark-topnav__tab {
        padding-inline: var(--ark-space-2);
    }
}

.ark-topnav__tab:hover {
    background: rgb(var(--v-theme-brand-soft));
}

.ark-topnav__tab--active {
    color: rgb(var(--v-theme-primary));
    border-bottom-color: rgb(var(--v-theme-primary));
}

.ark-topnav__caret {
    flex: 0 0 14px;
    opacity: 0.55;
}

.ark-topnav__tab:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}
</style>

<!-- v-menu の内容は body 直下へ teleport されるため scoped では届かない -->
<style>
.ark-topnav-menu .v-list {
    min-width: 220px;
    padding-block: 4px;
    border: 1px solid #d9dee5;
    border-radius: var(--ark-radius);
    box-shadow: 0 8px 24px rgb(18 25 60 / 14%);
}

.ark-topnav-menu .v-list-subheader {
    min-height: 26px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    opacity: 0.6;
}

/* カテゴリ行も項目行と同じ高さ・余白・アイコン幅にそろえ、太さだけで階層を示す。 */
.ark-topnav-menu__group-activator .v-list-item-title {
    font-weight: 700;
}

.ark-topnav-menu .v-list-item__append .v-icon {
    opacity: 0.55;
}

.ark-topnav-menu .v-list-item {
    min-height: 36px !important;
    padding-inline: 12px !important;
    border-radius: 6px;
}

.ark-topnav-menu .v-list--nav {
    padding-inline: 4px;
}

.ark-topnav-menu .v-list-item-title {
    font-size: 0.85rem;
}

.ark-topnav-menu .v-list-item__prepend {
    width: 26px;
    margin-inline-end: 6px !important;
}

.ark-mobilenav__subheader {
    min-height: 24px;
    padding-inline-start: 28px !important;
    font-size: 0.6875rem;
    font-weight: 700;
    opacity: 0.6;
}
</style>
