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
}

interface SharedPageProps {
    name: string;
    auth: {
        user: AuthUser | null;
        can: AuthPermissions;
    };
    flash: {
        success?: string;
        error?: string;
    };
}

declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: SharedPageProps;
    }
}
