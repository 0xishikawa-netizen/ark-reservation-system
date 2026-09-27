<script setup lang="ts">
/**
 * メニュー設定：利用できる具体ブースと、施術に必要な資格（Task 11-28）。
 * ブース未指定のメニューは従来どおり全有効ブースから選べる。
 */
import { MESSAGES } from '@/constants/messages';

interface BoothOption { id: number; name: string; is_active: boolean }
interface QualificationOption { id: number; name: string; is_active: boolean }

defineProps<{
    booths: BoothOption[];
    qualifications: QualificationOption[];
    boothErrors?: string | string[];
    qualificationErrors?: string | string[];
}>();

const boothIds = defineModel<number[]>('boothIds', { required: true });
const qualificationIds = defineModel<number[]>('qualificationIds', { required: true });
const labels = MESSAGES.bookingResources;
</script>

<template>
    <v-autocomplete
        v-model="boothIds"
        :label="labels.serviceBooths"
        :items="booths"
        item-title="name"
        item-value="id"
        multiple
        chips
        closable-chips
        :hint="labels.serviceBoothsHint"
        persistent-hint
        :error-messages="boothErrors"
        class="mb-3"
        data-testid="service-booths"
    >
        <template #item="{ props: itemProps, item }">
            <v-list-item v-bind="itemProps" :subtitle="item.raw.is_active ? undefined : labels.inactive" />
        </template>
    </v-autocomplete>
    <v-autocomplete
        v-model="qualificationIds"
        :label="labels.serviceQualifications"
        :items="qualifications"
        item-title="name"
        item-value="id"
        multiple
        chips
        closable-chips
        :hint="labels.serviceQualificationsHint"
        persistent-hint
        :error-messages="qualificationErrors"
        data-testid="service-qualifications"
    >
        <template #item="{ props: itemProps, item }">
            <v-list-item v-bind="itemProps" :subtitle="item.raw.is_active ? undefined : labels.inactive" />
        </template>
    </v-autocomplete>
</template>
