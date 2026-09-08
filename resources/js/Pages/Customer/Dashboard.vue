<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });
</script>

<template>
    <Head title="マイページ" />

    <v-card title="マイページ">
        <v-card-text>
            「予約する」から新しい予約を、「予約一覧」から予約内容を確認できます。
        </v-card-text>
    </v-card>
</template>
