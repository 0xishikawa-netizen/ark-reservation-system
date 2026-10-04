<script setup lang="ts">
import { ArkCalendar } from "@/components/ark";
import { dayLabel } from "@/components/admin/schedule/scheduleFormat";

/**
 * ドラッグ&ドロップで予約／予定ブロックを動かした時の確認ダイアログ（誤操作防止・§14, §40）。
 * 予約と予定ブロックで中身は同じなので、見出し・対象名などは呼び出し側から渡す。
 */
defineProps<{
    open: boolean;
    title: string;
    name: string;
    subtitle?: string | null;
    beforeLabel: string;
    afterLabel: string;
    /** 担当・ブースが変わる時の説明（変わらなければ null）。 */
    laneChangeLabel?: string | null;
    targetDate: string;
    submitting: boolean;
}>();

defineEmits<{
    cancel: [];
    confirm: [];
    "update:targetDate": [value: string];
}>();
</script>

<template>
    <v-dialog
        :model-value="open"
        max-width="420"
        @update:model-value="(v) => { if (!v) $emit('cancel'); }"
    >
        <v-card v-if="open">
            <v-card-title class="text-subtitle-1 font-weight-bold">{{ title }}</v-card-title>
            <v-card-text>
                <div class="mb-3">
                    <div class="font-weight-medium">{{ name }}</div>
                    <div v-if="subtitle" class="text-body-2 text-medium-emphasis">{{ subtitle }}</div>
                </div>
                <div class="ark-move-compare">
                    <div>
                        <div class="text-caption text-medium-emphasis">変更前</div>
                        <div class="text-body-1">{{ beforeLabel }}</div>
                    </div>
                    <v-icon icon="mdi-arrow-right" class="mx-2" />
                    <div>
                        <div class="text-caption text-medium-emphasis">変更後</div>
                        <div class="text-body-1 font-weight-bold text-primary">{{ afterLabel }}</div>
                    </div>
                </div>
                <div v-if="laneChangeLabel" class="text-body-2 mt-3">
                    <v-icon icon="mdi-account-switch-outline" size="16" class="mr-1" />
                    {{ laneChangeLabel }}
                </div>
                <!-- 任意の日付へ変更（§4, §6）。前日/今日/翌日ボタンへのドロップは既存どおり ±1日。 -->
                <v-menu :close-on-content-click="false" location="bottom start">
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            variant="text"
                            size="small"
                            color="accent"
                            prepend-icon="mdi-calendar-edit-outline"
                            class="mt-3"
                        >
                            日付を変更（{{ dayLabel(targetDate) }}）
                        </v-btn>
                    </template>
                    <v-card>
                        <ArkCalendar
                            :model-value="targetDate"
                            @update:model-value="$emit('update:targetDate', $event)"
                        />
                    </v-card>
                </v-menu>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" :disabled="submitting" @click="$emit('cancel')">キャンセル</v-btn>
                <v-btn color="primary" variant="flat" :loading="submitting" @click="$emit('confirm')">
                    変更する
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.ark-move-compare {
    display: flex;
    align-items: center;
    padding: var(--ark-space-3);
    background: rgba(var(--v-theme-on-surface), 0.04);
    border-radius: var(--ark-radius);
}
</style>
