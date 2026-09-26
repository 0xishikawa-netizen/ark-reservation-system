/**
 * ヘッダー「設定」メニューの構成。
 * 第1階層はカテゴリだけに統一し（項目が1つのカテゴリもネストする）、各画面は必ずカテゴリの下に置く。
 * 画面への route（href）は変更しない。
 */

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
    { title: '店舗設定', icon: 'mdi-storefront-outline' },
    { title: '予約設定', icon: 'mdi-calendar-check-outline' },
    { title: '回数券・月額', icon: 'mdi-wallet-membership' },
    { title: '権限管理', icon: 'mdi-shield-lock-outline' },
] as const;

export type SettingsCategory = (typeof SETTINGS_CATEGORIES)[number]['title'];

export interface SettingsNavigationItem {
    title: string;
    href: string;
    disabled: false;
    icon: string;
    group: '設定';
    subgroup: SettingsCategory;
}

export interface SettingsSection {
    title: SettingsCategory;
    icon: string;
    items: SettingsNavigationItem[];
}

const item = (title: string, href: string, icon: string, subgroup: SettingsCategory): SettingsNavigationItem => (
    { title, href, disabled: false, icon, group: '設定', subgroup }
);

export function settingsNavigationItems(can: SettingsPermissions): SettingsNavigationItem[] {
    return [
        ...(can.staffManage ? [item('スタッフ', '/admin/staff', 'mdi-account-group-outline', '店舗設定')] : []),
        ...(can.servicesManage ? [item('メニュー', '/admin/services', 'mdi-clipboard-text-outline', '店舗設定')] : []),
        ...(can.settingsManage ? [
            item('商品', '/admin/products', 'mdi-package-variant-closed', '店舗設定'),
            item('業務マスタ', '/admin/settings/business-masters', 'mdi-database-cog-outline', '店舗設定'),
        ] : []),
        ...(can.boothsManage ? [item('ブース', '/admin/booths', 'mdi-door-open', '店舗設定')] : []),
        ...(can.shiftsManage ? [item('勤務枠', '/admin/staff-shifts', 'mdi-calendar-clock-outline', '店舗設定')] : []),
        ...(can.settingsManage ? [
            item('予約ポリシー', '/admin/settings/reservation', 'mdi-calendar-alert-outline', '予約設定'),
            item('通知設定', '/admin/settings/notifications', 'mdi-bell-ring-outline', '予約設定'),
        ] : []),
        ...(can.ticketProductsManage ? [item('回数券商品', '/admin/ticket-products', 'mdi-ticket-confirmation-outline', '回数券・月額')] : []),
        ...(can.ticketPolicyManage ? [item('回数券運用設定', '/admin/settings/tickets', 'mdi-tune-variant', '回数券・月額')] : []),
        ...(can.membershipManage ? [item('月額プラン', '/admin/membership-plans', 'mdi-card-account-details-outline', '回数券・月額')] : []),
        ...(can.rolesManage ? [item('ロール権限', '/admin/settings/roles', 'mdi-shield-account-outline', '権限管理')] : []),
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
