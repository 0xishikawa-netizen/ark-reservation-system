import '@inertiajs/core';

interface AuthUser {
    id: number;
    name: string;
    email: string;
    roles: string[];
    email_verified: boolean;
    two_factor_enabled: boolean;
}

interface AuthPermissions {
    staffManage: boolean;
    servicesManage: boolean;
    boothsManage: boolean;
    shiftsManage: boolean;
    customersView: boolean;
    customersManage: boolean;
    reservationsView: boolean;
    reservationsManage: boolean;
    failedJobsView: boolean;
    auditLogsView: boolean;
    ticketPolicyManage: boolean;
    ticketProductsManage: boolean;
    ticketGrant: boolean;
    membershipManage: boolean;
    integrationsView: boolean;
    integrationsManage: boolean;
    settingsManage: boolean;
    rolesManage: boolean;
    reportsView: boolean;
    reportsManage: boolean;
    salesView: boolean;
    checkoutsManage: boolean;
}

interface SharedPageProps {
    name: string;
    auth: {
        user: AuthUser | null;
        can: AuthPermissions;
        reportRoutes: {
            dailyNotes: string;
            monthly: string;
            customers: string;
            staffUtilization: string;
            timeBands: string;
            annual: string;
            staffSales?: string;
            courseSales?: string;
        };
    };
    flash: {
        success?: string;
        error?: string;
        info?: string;
    };
}

declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: SharedPageProps;
    }
}
