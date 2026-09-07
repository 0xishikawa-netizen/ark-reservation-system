<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface FailedJob {
    uuid: string;
    connection: string;
    queue: string;
    failed_at: string;
    exception_first_line: string;
}

interface FailedJobsPaginator {
    data: FailedJob[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    jobs: FailedJobsPaginator;
    count: number;
}>();

const currentPage = computed<number>({
    get: () => props.jobs.current_page,
    set: (page) => {
        if (page === props.jobs.current_page) {
            return;
        }

        router.get(
            '/admin/system/failed-jobs',
            { page },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});
</script>

<template>
    <Head title="失敗ジョブ" />

    <div class="d-flex align-center ga-3 mb-6">
        <h1 class="text-h4">失敗ジョブ</h1>
        <v-chip color="error" variant="tonal">{{ count }} 件</v-chip>
    </div>

    <v-card>
        <v-card-text class="pa-0">
            <v-table>
                <thead>
                    <tr>
                        <th>UUID</th>
                        <th>Connection</th>
                        <th>Queue</th>
                        <th>失敗日時</th>
                        <th>例外（1行目）</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="job in jobs.data" :key="job.uuid">
                        <td class="text-no-wrap">{{ job.uuid }}</td>
                        <td>{{ job.connection }}</td>
                        <td>{{ job.queue }}</td>
                        <td class="text-no-wrap">{{ job.failed_at }}</td>
                        <td
                            class="exception-cell"
                            :title="job.exception_first_line"
                        >
                            {{ job.exception_first_line }}
                        </td>
                    </tr>
                    <tr v-if="jobs.data.length === 0">
                        <td colspan="5" class="text-center text-medium-emphasis py-8">
                            失敗ジョブはありません。
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card-text>
    </v-card>

    <v-pagination
        v-if="jobs.last_page > 1"
        v-model="currentPage"
        :length="jobs.last_page"
        class="mt-6"
        aria-label="失敗ジョブのページ"
    />
</template>

<style scoped>
.exception-cell {
    max-width: 40rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
