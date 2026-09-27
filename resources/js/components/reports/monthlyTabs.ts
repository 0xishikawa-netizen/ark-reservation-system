import { MESSAGES } from '@/constants/messages';

/** 月次レポートのタブ（Task 11-31）。同じ月を分析する画面群を1つの業務単位として並べる。 */
export type MonthlyTabKey =
    | 'overview' | 'monthly' | 'ledger' | 'notes' | 'customers' | 'reservations'
    | 'staff' | 'bands' | 'staffSales' | 'courses';

export interface MonthlyTab { key: MonthlyTabKey; title: string; href: string }

/**
 * 各タブへのリンク。対象月（と売上基準・基準日）を引き継ぎ、タブを移っても月を選び直さなくてよいようにする。
 * 既存の画面URLはそのまま使う（ディープリンクを壊さない）。
 */
export function monthlyTabs(monthKey: string, options: { basis?: string | null; asOf?: string | null } = {}): MonthlyTab[] {
    const [year, month] = monthKey.split('-').map(Number);
    const query = (extra: Record<string, string | null | undefined>): string => {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(extra)) {
            if (value !== null && value !== undefined && value !== '') params.set(key, value);
        }
        const text = params.toString();
        return text === '' ? '' : `?${text}`;
    };
    const ym = { year: String(year), month: String(month) };
    const withBasis = { ...ym, basis: options.basis ?? undefined };
    const labels = MESSAGES.monthlyHub.tabs;

    return [
        { key: 'overview', title: labels.overview, href: `/admin/reports/overview${query({ ...withBasis, as_of_date: options.asOf ?? undefined })}` },
        { key: 'monthly', title: labels.monthly, href: `/admin/reports/monthly${query(withBasis)}` },
        { key: 'ledger', title: labels.ledger, href: `/admin/reports/daily-ledger${query(ym)}` },
        { key: 'notes', title: labels.notes, href: `/admin/reports/daily-notes${query({ month: monthKey })}` },
        { key: 'customers', title: labels.customers, href: `/admin/reports/customers${query({ ...ym, as_of_date: options.asOf ?? undefined })}` },
        { key: 'reservations', title: labels.reservations, href: `/admin/reports/reservation-analysis${query(ym)}` },
        { key: 'staff', title: labels.staff, href: `/admin/reports/staff-utilization${query(ym)}` },
        { key: 'bands', title: labels.bands, href: `/admin/reports/time-bands${query(ym)}` },
        { key: 'staffSales', title: labels.staffSales, href: `/admin/reports/staff-sales${query(withBasis)}` },
        { key: 'courses', title: labels.courses, href: `/admin/reports/course-sales${query(withBasis)}` },
    ];
}
