import { describe, expect, it } from 'vitest';
import { settingsNavigationItems, settingsSections } from './settingsNavigation';

const all = {
    staffManage: true, servicesManage: true, settingsManage: true, boothsManage: true, shiftsManage: true,
    ticketProductsManage: true, ticketPolicyManage: true, membershipManage: true, rolesManage: true,
};

describe('設定メニュー', () => {
    it('第1階層をカテゴリに統一し、全項目がどこかのカテゴリにネストされる', () => {
        const sections = settingsSections(settingsNavigationItems(all));
        expect(sections.map((section) => section.title)).toEqual(['店舗設定', '予約設定', '回数券・月額', '権限管理']);
        expect(sections.every((section) => section.icon.startsWith('mdi-'))).toBe(true);
        expect(sections[0].items.map((item) => item.title)).toEqual(['スタッフ', 'メニュー', '商品', '業務マスタ', 'ブース', '勤務枠']);
        expect(sections[1].items.map((item) => item.title)).toEqual(['予約ポリシー', '通知設定']);
        expect(sections[2].items.map((item) => item.title)).toEqual(['回数券商品', '回数券運用設定', '月額プラン']);
        expect(sections[3].items.map((item) => item.title)).toEqual(['ロール権限']);
        expect(sections.flatMap((section) => section.items).every((item) => item.icon.startsWith('mdi-'))).toBe(true);
    });

    it('既存画面の route を変えない', () => {
        expect(settingsNavigationItems(all).map((item) => item.href)).toEqual([
            '/admin/staff', '/admin/services', '/admin/products', '/admin/settings/business-masters', '/admin/booths',
            '/admin/staff-shifts', '/admin/settings/reservation', '/admin/settings/notifications', '/admin/ticket-products',
            '/admin/settings/tickets', '/admin/membership-plans', '/admin/settings/roles',
        ]);
    });

    it('権限のない項目は出さず、項目のないカテゴリも出さない（1項目だけのカテゴリもネストのまま）', () => {
        const sections = settingsSections(settingsNavigationItems({
            ...Object.fromEntries(Object.keys(all).map((key) => [key, false])) as typeof all,
            rolesManage: true,
        }));
        expect(sections.map((section) => section.title)).toEqual(['権限管理']);
        expect(sections[0].items.map((item) => item.title)).toEqual(['ロール権限']);
    });
});
