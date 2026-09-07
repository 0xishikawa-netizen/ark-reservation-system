<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps<{
    failedJobsCount: number;
}>();

const today = new Intl.DateTimeFormat('ja-JP', {
    dateStyle: 'long',
}).format(new Date());
</script>

<template>
    <Head title="管理ダッシュボード" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">管理ダッシュボード</h1>
        <span class="text-medium-emphasis">{{ today }}</span>
    </div>
    <v-card max-width="480" title="失敗ジョブ">
        <v-card-text class="text-h5">
            {{ failedJobsCount }} 件
        </v-card-text>
    </v-card>
</template>
