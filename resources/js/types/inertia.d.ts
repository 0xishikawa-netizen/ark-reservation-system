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
    failedJobsView: boolean;
    auditLogsView: boolean;
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
