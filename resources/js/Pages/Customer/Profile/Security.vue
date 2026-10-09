<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: CustomerLayout });

defineProps<{
    google: { linked: boolean; email: string | null; linked_at: string | null };
    hasPassword: boolean;
}>();

const page = usePage();
const linkError = computed<string | undefined>(
    () => (page.props.errors as Record<string, string> | undefined)?.google,
);

const linkGoogle = (): void => {
    // password.confirm を挟むため通常のブラウザ遷移で開始する。
    window.location.href = '/auth/google/link?return=/mypage/security';
};

const unlinkGoogle = (): void => {
    router.delete('/auth/google/unlink', { preserveScroll: true });
};
</script>

<template>
    <Head :title="MESSAGES.customerUi.security.title" />

    <PageHeader
        :title="MESSAGES.customerUi.security.title"
        :subtitle="MESSAGES.customerUi.security.subtitle"
    />

    <SectionCard :title="MESSAGES.customerUi.security.loginMethods">
        <v-list lines="two" class="pa-0">
            <v-list-item
                :title="MESSAGES.customerUi.security.emailPassword"
                :subtitle="hasPassword ? MESSAGES.customerUi.security.configured : MESSAGES.common.notSet"
            >
                <template #prepend>
                    <v-icon icon="mdi-form-textbox-password" />
                </template>
                <template #append>
                    <v-chip
                        :color="hasPassword ? 'success' : 'grey'"
                        size="small"
                        variant="flat"
                    >
                        {{ hasPassword ? MESSAGES.customerUi.security.enabled : MESSAGES.common.notSet }}
                    </v-chip>
                </template>
            </v-list-item>
        </v-list>
    </SectionCard>

    <SectionCard :title="MESSAGES.customerUi.security.linkedAccounts" class="mt-4">
        <v-alert
            v-if="linkError"
            type="error"
            variant="tonal"
            density="comfortable"
            class="mb-4"
        >
            {{ linkError }}
        </v-alert>

        <div class="d-flex align-center ga-3 flex-wrap">
            <v-icon icon="mdi-google" size="28" />
            <div class="flex-grow-1">
                <div class="font-weight-medium">Google</div>
                <div class="text-body-2 text-medium-emphasis">
                    <template v-if="google.linked">
                        {{ google.email ? fillMessage(MESSAGES.customerUi.security.linkedWithEmail, { email: google.email }) : MESSAGES.customerUi.security.linked }}
                    </template>
                    <template v-else>{{ MESSAGES.customerUi.security.notLinked }}</template>
                </div>
            </div>
            <v-chip
                :color="google.linked ? 'success' : 'grey'"
                size="small"
                variant="flat"
            >
                {{ google.linked ? MESSAGES.customerUi.security.linked : MESSAGES.customerUi.security.notLinked }}
            </v-chip>
        </div>

        <div class="mt-4">
            <v-btn
                v-if="!google.linked"
                color="primary"
                variant="flat"
                prepend-icon="mdi-google"
                @click="linkGoogle"
            >
                {{ MESSAGES.customerUi.security.linkGoogle }}
            </v-btn>
            <v-btn
                v-else
                color="error"
                variant="outlined"
                :disabled="!hasPassword"
                @click="unlinkGoogle"
            >
                {{ MESSAGES.customerUi.security.unlink }}
            </v-btn>
            <p
                v-if="google.linked && !hasPassword"
                class="text-caption text-medium-emphasis mt-2 mb-0"
            >
                {{ MESSAGES.auth.setPasswordBeforeUnlink }}
            </p>
        </div>
    </SectionCard>
</template>
