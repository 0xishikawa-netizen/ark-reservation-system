<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import {
    DEFAULT_NOTIFICATION_REPEAT,
    DEFAULT_NOTIFICATION_SOUND,
    NOTIFICATION_REPEAT_OPTIONS,
    NOTIFICATION_SOUND_OPTIONS,
    type NotificationRepeatMode,
    type NotificationSoundType,
    isNotificationRepeatMode,
    isNotificationSoundType,
    playNotificationSound,
    unlockNotificationSound,
} from '@/composables/notificationSound';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface NotificationSoundSettings {
    enabled: boolean;
    type: string;
    volume: number;
    repeat: string;
}

const props = withDefaults(
    defineProps<{
        settings: NotificationSoundSettings;
        errors?: Record<string, string | undefined>;
    }>(),
    {
        errors: () => ({}),
    },
);

const enabled = ref(props.settings.enabled);
const soundType = ref<NotificationSoundType>(
    isNotificationSoundType(props.settings.type) ? props.settings.type : DEFAULT_NOTIFICATION_SOUND,
);
const volume = ref(props.settings.volume);
const repeat = ref<NotificationRepeatMode>(
    isNotificationRepeatMode(props.settings.repeat) ? props.settings.repeat : DEFAULT_NOTIFICATION_REPEAT,
);
const processing = ref(false);

interface SoundItem {
    title?: string;
    value?: NotificationSoundType;
    description?: string;
    /** Vuetify のリスト見出し・区切り線 */
    type?: 'subheader' | 'divider';
}

const toItem = (o: (typeof NOTIFICATION_SOUND_OPTIONS)[number]): SoundItem => ({
    title: o.label,
    value: o.value,
    description: o.description,
});

/** プルダウンの項目。気づきやすい音と控えめな音を見出しで分ける。 */
const soundItems = computed<SoundItem[]>(() => [
    { type: 'subheader', title: '通知音' },
    ...NOTIFICATION_SOUND_OPTIONS.filter((o) => o.loud).map(toItem),
    { type: 'divider' },
    { type: 'subheader', title: '控えめな音' },
    ...NOTIFICATION_SOUND_OPTIONS.filter((o) => !o.loud).map(toItem),
]);

function previewItem(item: SoundItem): void {
    if (item.value !== undefined) {
        preview(item.value);
    }
}


async function preview(type: NotificationSoundType = soundType.value): Promise<void> {
    // ボタン押下などユーザー操作の中なので、ここで確実に音を出せる。
    await unlockNotificationSound();
    playNotificationSound(type, volume.value);
}

function onSoundChange(type: NotificationSoundType): void {
    soundType.value = type;
    void preview(type);
}

function save(): void {
    router.patch(
        '/admin/settings/notifications',
        {
            enabled: enabled.value,
            type: soundType.value,
            volume: volume.value,
            repeat: repeat.value,
        },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="通知設定" />

    <div class="ark-settings-page">
        <PageHeader
            title="通知設定"
            subtitle="ブッキングボードで予約が入った時の通知方法を設定します。"
        />

        <SectionCard title="新規予約の通知音">
            <v-switch
                v-model="enabled"
                color="primary"
                inset
                hide-details="auto"
                :label="enabled ? '通知音を鳴らす' : '通知音を鳴らさない'"
                :error-messages="errors.enabled"
            />

            <div class="ns" :class="{ 'ns--disabled': !enabled }">
                <!-- 音の種類 -->
                <div class="ns__row">
                    <div class="ns__label">音の種類</div>
                    <div class="ns__field ns__sound">
                        <v-select
                            class="ns__select"
                            :model-value="soundType"
                            :items="soundItems"
                            :disabled="!enabled"
                            variant="outlined"
                            density="comfortable"
                            hide-details="auto"
                            prepend-inner-icon="mdi-bell-ring-outline"
                            :error-messages="errors.type"
                            aria-label="通知音の種類"
                            @update:model-value="onSoundChange"
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps" :subtitle="(item.raw as SoundItem).description">
                                    <template #append>
                                        <v-btn
                                            icon="mdi-play-circle-outline"
                                            variant="text"
                                            size="small"
                                            color="primary"
                                            :aria-label="`${item.title}を試聴`"
                                            @click.stop="previewItem(item.raw as SoundItem)"
                                        />
                                    </template>
                                </v-list-item>
                            </template>
                        </v-select>
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="large"
                            prepend-icon="mdi-play"
                            :disabled="!enabled"
                            class="ns__play"
                            @click="preview()"
                        >
                            試聴
                        </v-btn>
                    </div>
                </div>

                <!-- 音量 -->
                <div class="ns__row">
                    <div class="ns__label">音量</div>
                    <div class="ns__field">
                        <v-slider
                            v-model="volume"
                            :min="10"
                            :max="100"
                            :step="10"
                            :disabled="!enabled"
                            color="primary"
                            thumb-label
                            hide-details="auto"
                            prepend-icon="mdi-volume-low"
                            append-icon="mdi-volume-high"
                            :error-messages="errors.volume"
                            aria-label="通知音の音量"
                            @end="preview()"
                        >
                            <template #thumb-label="{ modelValue }">{{ modelValue }}%</template>
                        </v-slider>
                    </div>
                    <p class="ns__desc">{{ MESSAGES.settings.notificationSpeakerHint }}</p>
                </div>

                <!-- 繰り返し -->
                <div class="ns__row">
                    <div class="ns__label">繰り返し</div>
                    <div class="ns__field">
                        <v-btn-toggle
                            v-model="repeat"
                            mandatory
                            color="primary"
                            variant="outlined"
                            density="comfortable"
                            divided
                            :disabled="!enabled"
                            aria-label="通知音の繰り返し"
                        >
                            <v-btn
                                v-for="option in NOTIFICATION_REPEAT_OPTIONS"
                                :key="option.value"
                                :value="option.value"
                                :title="option.description"
                            >
                                {{ option.label }}
                            </v-btn>
                        </v-btn-toggle>
                    </div>
                    <p v-if="errors.repeat" class="ns__desc text-error">{{ errors.repeat }}</p>
                </div>
            </div>

            <div class="d-flex justify-end mt-6">
                <v-btn color="primary" :loading="processing" @click="save">
                    保存
                </v-btn>
            </div>
        </SectionCard>
    </div>
</template>

<style scoped>
.ark-settings-page {
    max-width: 960px;
    margin-inline: auto;
}

.ns {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-5);
    margin-top: var(--ark-space-5);
}

.ns--disabled {
    opacity: 0.55;
}

.ns__row {
    display: grid;
    grid-template-columns: 120px minmax(0, 1fr);
    column-gap: var(--ark-space-4);
    row-gap: var(--ark-space-1);
    align-items: center;
}

.ns__label {
    font-size: 0.875rem;
    font-weight: 700;
}

.ns__field {
    min-width: 0;
    max-width: 560px;
}

.ns__sound {
    display: flex;
    align-items: flex-start;
    gap: var(--ark-space-2);
}

/* 選んだ音の名前の長さでプルダウンの幅が変わらないよう固定する。 */
.ns__select {
    flex: 0 0 320px;
    width: 320px;
    max-width: 100%;
}

.ns__play {
    flex: 0 0 auto;
    height: 48px;
}

.ns__desc {
    grid-column: 2;
    margin: 0;
    font-size: 0.8125rem;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

@media (max-width: 600px) {
    .ns__row {
        grid-template-columns: minmax(0, 1fr);
    }

    .ns__select {
        flex: 1 1 auto;
        width: auto;
    }

    .ns__desc {
        grid-column: 1;
    }
}
</style>
