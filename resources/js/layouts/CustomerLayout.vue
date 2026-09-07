<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

const logout = (): void => {
    router.post('/logout');
};
</script>

<template>
    <v-app>
        <v-app-bar color="primary" density="comfortable">
            <v-app-bar-title>{{ page.props.name }}</v-app-bar-title>

            <template #append>
                <span v-if="page.props.auth.user" class="d-none d-sm-inline mr-3">
                    {{ page.props.auth.user.name }}
                </span>
                <v-btn
                    v-if="page.props.auth.user"
                    variant="text"
                    @click="logout"
                >
                    ログアウト
                </v-btn>
            </template>
        </v-app-bar>

        <v-main class="bg-grey-lighten-4">
            <v-container class="customer-content px-4 py-6">
                <nav aria-label="顧客メニュー" class="mb-4">
                    <v-btn variant="text" color="primary" @click="router.visit('/')">
                        マイページ
                    </v-btn>
                </nav>

                <v-alert
                    v-if="page.props.flash.success"
                    type="success"
                    class="mb-4"
                >
                    {{ page.props.flash.success }}
                </v-alert>
                <v-alert
                    v-if="page.props.flash.error"
                    type="error"
                    class="mb-4"
                >
                    {{ page.props.flash.error }}
                </v-alert>

                <slot />
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.customer-content {
    max-width: 48rem;
}
</style>
