/**
 * ヘッダー「設定」メニューの構成。
 * 第1階層はカテゴリだけに統一し（項目が1つのカテゴリもネストする）、各画面は必ずカテゴリの下に置く。
 * 画面への route（href）は変更しない。
 */
import { MESSAGES } from '@/constants/messages';

/** 設定メニューの文言（カテゴリ名・項目名）。 */
const NAV = MESSAGES.customerUi.navigation;
const CATEGORY = NAV.settingsCategories;
const ITEM = NAV.settingsItems;

interface SettingsPermissions {
    staffManage: boolean;
    servicesManage: boolean;
    settingsManage: boolean;
    boothsManage: boolean;
    shiftsManage: boolean;
    ticketProductsManage: boolean;
    ticketPolicyManage: boolean;
    membershipManage: boolean;
    rolesManage: boolean;
}

/** カテゴリの表示順・アイコン。 */
export const SETTINGS_CATEGORIES = [
    { title: CATEGORY.store, icon: 'mdi-storefront-outline' },
    { title: CATEGORY.reservation, icon: 'mdi-calendar-check-outline' },
    { title: CATEGORY.ticketMembership, icon: 'mdi-wallet-membership' },
    { title: CATEGORY.permissions, icon: 'mdi-shield-lock-outline' },
] as const;

export type SettingsCategory = (typeof SETTINGS_CATEGORIES)[number]['title'];

export interface SettingsNavigationItem {
    title: string;
    href: string;
    disabled: false;
    icon: string;
    group: typeof NAV.groups.settings;
    subgroup: SettingsCategory;
}

export interface SettingsSection {
    title: SettingsCategory;
    icon: string;
    items: SettingsNavigationItem[];
}

const item = (title: string, href: string, icon: string, subgroup: SettingsCategory): SettingsNavigationItem => (
    { title, href, disabled: false, icon, group: NAV.groups.settings, subgroup }
);

export function settingsNavigationItems(can: SettingsPermissions): SettingsNavigationItem[] {
    return [
        ...(can.staffManage ? [item(ITEM.staff, '/admin/staff', 'mdi-account-group-outline', CATEGORY.store)] : []),
        ...(can.servicesManage ? [item(ITEM.services, '/admin/services', 'mdi-clipboard-text-outline', CATEGORY.store)] : []),
        ...(can.settingsManage ? [
            item(ITEM.products, '/admin/products', 'mdi-package-variant-closed', CATEGORY.store),
            item(ITEM.businessMasters, '/admin/settings/business-masters', 'mdi-database-cog-outline', CATEGORY.store),
        ] : []),
        ...(can.boothsManage ? [item(ITEM.booths, '/admin/booths', 'mdi-door-open', CATEGORY.store)] : []),
        ...(can.shiftsManage ? [item(ITEM.staffShifts, '/admin/staff-shifts', 'mdi-calendar-clock-outline', CATEGORY.store)] : []),
        ...(can.settingsManage ? [
            item(ITEM.reservationPolicy, '/admin/settings/reservation', 'mdi-calendar-alert-outline', CATEGORY.reservation),
            item(ITEM.notifications, '/admin/settings/notifications', 'mdi-bell-ring-outline', CATEGORY.reservation),
        ] : []),
        ...(can.ticketProductsManage ? [item(ITEM.ticketProducts, '/admin/ticket-products', 'mdi-ticket-confirmation-outline', CATEGORY.ticketMembership)] : []),
        ...(can.ticketPolicyManage ? [item(ITEM.ticketPolicy, '/admin/settings/tickets', 'mdi-tune-variant', CATEGORY.ticketMembership)] : []),
        ...(can.membershipManage ? [item(ITEM.membershipPlans, '/admin/membership-plans', 'mdi-card-account-details-outline', CATEGORY.ticketMembership)] : []),
        ...(can.rolesManage ? [item(ITEM.roles, '/admin/settings/roles', 'mdi-shield-account-outline', CATEGORY.permissions)] : []),
    ];
}

/** 設定メニューの項目をカテゴリ順にまとめる（項目のないカテゴリは出さない）。 */
export function settingsSections(items: { subgroup?: string }[]): SettingsSection[] {
    return SETTINGS_CATEGORIES
        .map((category) => ({
            title: category.title,
            icon: category.icon,
            items: items.filter((entry): entry is SettingsNavigationItem => entry.subgroup === category.title),
        }))
        .filter((section) => section.items.length > 0);
}
