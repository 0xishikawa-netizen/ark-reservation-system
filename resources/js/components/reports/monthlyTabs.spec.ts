import { describe, expect, it } from 'vitest';
import { monthlyTabs } from './monthlyTabs';

describe('月次レポートのタブ（Task 11-31）', () => {
    it('10タブを既存URLのまま並べ、対象月・売上基準を引き継ぐ', () => {
        const tabs = monthlyTabs('2026-09', { basis: 'payment_date' });

        expect(tabs.map((tab) => tab.title)).toEqual(['概要', '月計表', '日計明細', '日報', '顧客統計', '予約分析', 'スタッフ稼働', '時間帯別', 'スタッフ売上', 'コース・物販']);
        expect(tabs.find((tab) => tab.key === 'monthly')?.href).toBe('/admin/reports/monthly?year=2026&month=9&basis=payment_date');
        expect(tabs.find((tab) => tab.key === 'notes')?.href).toBe('/admin/reports/daily-notes?month=2026-09');
        expect(tabs.find((tab) => tab.key === 'staff')?.href).toBe('/admin/reports/staff-utilization?year=2026&month=9');
        expect(tabs.every((tab) => tab.href.includes('2026'))).toBe(true);
    });
});
