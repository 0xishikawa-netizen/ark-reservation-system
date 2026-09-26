import { MESSAGES } from '@/constants/messages';

interface ReportPermissions {
    reportsView: boolean;
    salesView: boolean;
}

interface ReportRoutes {
    dailyNotes: string;
    monthly: string;
    customers: string;
    staffUtilization: string;
    timeBands: string;
    annual: string;
    staffSales?: string;
}

export interface ReportNavigationItem {
    title: string;
    href: string;
    disabled: false;
    icon: string;
    group: '集計';
}

export function reportNavigationItems(can: ReportPermissions, routes: ReportRoutes): ReportNavigationItem[] {
    if (!can.reportsView) {
        return [];
    }

    return [
        { title: MESSAGES.reporting.dailyNotesTitle, href: routes.dailyNotes, disabled: false, icon: 'mdi-text-box-edit-outline', group: '集計' },
        ...(can.salesView ? [
            { title: '月計', href: routes.monthly, disabled: false as const, icon: 'mdi-chart-box-outline', group: '集計' as const },
            { title: MESSAGES.reporting.annualTitle, href: routes.annual, disabled: false as const, icon: 'mdi-calendar-range', group: '集計' as const },
            ...(routes.staffSales ? [{ title: MESSAGES.reporting.staffSalesTitle, href: routes.staffSales, disabled: false as const, icon: 'mdi-account-cash-outline', group: '集計' as const }] : []),
        ] : []),
        { title: MESSAGES.reporting.customerTitle, href: routes.customers, disabled: false, icon: 'mdi-account-group-outline', group: '集計' },
        { title: MESSAGES.reporting.staffTitle, href: routes.staffUtilization, disabled: false, icon: 'mdi-chart-timeline-variant', group: '集計' },
        { title: MESSAGES.reporting.bandTitle, href: routes.timeBands, disabled: false, icon: 'mdi-clock-outline', group: '集計' },
    ];
}
