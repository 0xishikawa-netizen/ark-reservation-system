<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

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
    <Head title="セキュリティ設定" />

    <PageHeader
        title="セキュリティ設定"
        subtitle="ログイン方法と連携アカウントを管理できます。"
    />

    <SectionCard title="ログイン方法">
        <v-list lines="two" class="pa-0">
            <v-list-item
                title="メールアドレスとパスワード"
                :subtitle="hasPassword ? '設定済み' : '未設定'"
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
                        {{ hasPassword ? '有効' : '未設定' }}
                    </v-chip>
                </template>
            </v-list-item>
        </v-list>
    </SectionCard>

    <SectionCard title="連携アカウント" class="mt-4">
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
                        連携済み{{ google.email ? `（${google.email}）` : '' }}
                    </template>
                    <template v-else>未連携</template>
                </div>
            </div>
            <v-chip
                :color="google.linked ? 'success' : 'grey'"
                size="small"
                variant="flat"
            >
                {{ google.linked ? '連携済み' : '未連携' }}
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
                Google アカウントを連携
            </v-btn>
            <v-btn
                v-else
                color="error"
                variant="outlined"
                :disabled="!hasPassword"
                @click="unlinkGoogle"
            >
                連携を解除
            </v-btn>
            <p
                v-if="google.linked && !hasPassword"
                class="text-caption text-medium-emphasis mt-2 mb-0"
            >
                連携を解除するには、先にパスワードを設定してください（ログイン手段が無くなるのを防ぐため）。
            </p>
        </div>
    </SectionCard>
</template>
