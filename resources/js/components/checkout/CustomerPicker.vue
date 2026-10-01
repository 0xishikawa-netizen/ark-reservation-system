<script setup lang="ts">
import { ref, watch } from 'vue';
import { MESSAGES } from '@/constants/messages';

export interface PickedCustomer { user_id: number; name: string; kana: string | null; member_no: string }

const props = defineProps<{ endpoint: string; modelValue: PickedCustomer | null; label?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: PickedCustomer | null] }>();

const query = ref('');
const items = ref<PickedCustomer[]>(props.modelValue ? [props.modelValue] : []);
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

// 顧客検索は既存の予約用検索APIを再利用する（新しい検索定義は作らない）。
watch(query, (value) => {
    if (timer) clearTimeout(timer);
    if (!value || value.trim().length < 1) return;
    timer = setTimeout(async () => {
        loading.value = true;
        try {
            const response = await fetch(`${props.endpoint}?q=${encodeURIComponent(value.trim())}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            items.value = response.ok ? ((await response.json()) as PickedCustomer[]) : [];
        } finally {
            loading.value = false;
        }
    }, 250);
});

const title = (item: PickedCustomer): string => `${item.name}（${item.member_no}）`;
</script>

<template>
    <v-autocomplete
        :model-value="modelValue"
        v-model:search="query"
        :items="items"
        :item-title="title"
        :label="label ?? MESSAGES.checkout.customerSearch"
        :loading="loading"
        return-object
        no-filter
        clearable
        hide-details
        data-testid="customer-picker"
        @update:model-value="(value: PickedCustomer | null) => emit('update:modelValue', value)"
    />
</template>
