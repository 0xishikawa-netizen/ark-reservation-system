import { describe, expect, it } from 'vitest';
import { reportNavigationItems } from './reportNavigation';

const routes = {
    dailyNotes: '/named/daily-notes',
    monthly: '/named/monthly',
    customers: '/named/customers',
    staffUtilization: '/named/staff',
    timeBands: '/named/bands',
    annual: '/named/annual',
};

describe('集計ナビゲーション', () => {
    it('開発管理者に日報を含む6画面をnamed routeのURLで表示する', () => {
        const items = reportNavigationItems({ reportsView: true, salesView: true }, routes);

        expect(items.map((item) => item.title)).toEqual(['日報', '月計', '年間集計', '顧客統計', 'スタッフ稼働率', '時間帯別稼働率']);
        expect(items.map((item) => item.href)).toEqual([
            routes.dailyNotes, routes.monthly, routes.annual, routes.customers, routes.staffUtilization, routes.timeBands,
        ]);
        expect(items.every((item) => item.group === '集計')).toBe(true);
    });

    it('reports.viewのないスタッフに集計を表示しない', () => {
        expect(reportNavigationItems({ reportsView: false, salesView: false }, routes)).toEqual([]);
    });

    it('売上権限がなければ月計と年間だけ非表示にする', () => {
        expect(reportNavigationItems({ reportsView: true, salesView: false }, routes).map((item) => item.title))
            .toEqual(['日報', '顧客統計', 'スタッフ稼働率', '時間帯別稼働率']);
    });
});
