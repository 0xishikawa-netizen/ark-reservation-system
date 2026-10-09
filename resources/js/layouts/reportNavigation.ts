import { MESSAGES } from '@/constants/messages';

/** 集計メニューのグループ名。 */
const REPORTS_GROUP = MESSAGES.customerUi.navigation.groups.reports;

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
    courseSales?: string;
    overview?: string;
}

export interface ReportNavigationItem {
    title: string;
    href: string;
    disabled: false;
    icon: string;
    group: typeof REPORTS_GROUP;
}

export function reportNavigationItems(can: ReportPermissions, routes: ReportRoutes): ReportNavigationItem[] {
    if (!can.reportsView) {
        return [];
    }

    // 月次レポート（Task 11-31）：同じ月を分析する画面（月計表・日計明細・日報・顧客統計・予約分析・スタッフ稼働・
    // 時間帯別・スタッフ売上・コース/物販）は「月次レポート」のタブにまとめ、メニューは入口1つにする。
    // 概要は売上を含むため、売上権限が無い場合は従来どおり売上を含まない画面を個別に出す。
    if (can.salesView && routes.overview) {
        return [
            { title: MESSAGES.monthlyHub.title, href: routes.overview, disabled: false, icon: 'mdi-chart-box-outline', group: REPORTS_GROUP },
            { title: MESSAGES.reporting.annualTitle, href: routes.annual, disabled: false, icon: 'mdi-calendar-range', group: REPORTS_GROUP },
        ];
    }

    return [
        { title: MESSAGES.reporting.dailyNotesTitle, href: routes.dailyNotes, disabled: false, icon: 'mdi-text-box-edit-outline', group: REPORTS_GROUP },
        ...(can.salesView ? [
            { title: MESSAGES.customerUi.navigation.items.monthly, href: routes.monthly, disabled: false as const, icon: 'mdi-chart-box-outline', group: REPORTS_GROUP },
            { title: MESSAGES.reporting.annualTitle, href: routes.annual, disabled: false as const, icon: 'mdi-calendar-range', group: REPORTS_GROUP },
        ] : []),
        { title: MESSAGES.reporting.customerTitle, href: routes.customers, disabled: false, icon: 'mdi-account-group-outline', group: REPORTS_GROUP },
        { title: MESSAGES.reporting.staffTitle, href: routes.staffUtilization, disabled: false, icon: 'mdi-chart-timeline-variant', group: REPORTS_GROUP },
        { title: MESSAGES.reporting.bandTitle, href: routes.timeBands, disabled: false, icon: 'mdi-clock-outline', group: REPORTS_GROUP },
    ];
}
