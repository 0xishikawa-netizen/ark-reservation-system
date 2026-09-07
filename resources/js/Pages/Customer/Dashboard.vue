<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });
</script>

<template>
    <Head title="マイページ" />

    <v-card title="マイページ">
        <v-card-text>
            予約・回数券・利用権の機能は、今後のタスクで追加します。
        </v-card-text>
    </v-card>
</template>
