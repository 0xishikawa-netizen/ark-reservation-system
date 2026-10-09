<script setup lang="ts">
import { computed, nextTick, ref, useAttrs, watch } from 'vue';

// 親から渡された class/style は外側の div に当てる（v-menu が根要素だと親の scoped スタイルの幅指定が
// 効かず、時刻が見切れるため）。それ以外の属性は実際の入力欄(v-text-field)へ渡す。
defineOptions({ inheritAttrs: false });

type FieldDensity = 'default' | 'comfortable' | 'compact';

/** 1時間あたりの分数。 */
const MINUTES_PER_HOUR = 60;

const props = withDefaults(defineProps<{
    modelValue: string;
    label: string;
    clearable?: boolean;
    hideDetails?: boolean | 'auto';
    density?: FieldDensity;
    /** 選べる時刻の間隔（分）。既定は10分刻み（従来の time input の step=600 相当）。 */
    stepMinutes?: number;
    minTime?: string;
    maxTime?: string;
    /** 読み取り専用（確定済みの画面など）。時刻の一覧を開かない。 */
    readonly?: boolean;
    /** 幅いっぱいに広げる（例外の画面だけで使う）。 */
    block?: boolean;
}>(), {
    clearable: false,
    hideDetails: true,
    density: 'comfortable',
    stepMinutes: 10,
    minTime: '00:00',
    maxTime: '23:50',
    readonly: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const attrs = useAttrs();
const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const fieldAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;

    return rest;
});

const menuOpen = ref(false);
const listEl = ref<HTMLElement | null>(null);

function toMinutes(hhmm: string): number {
    const [h, m] = hhmm.split(':').map(Number);

    return h * MINUTES_PER_HOUR + m;
}

function toHHMM(totalMinutes: number): string {
    const h = Math.floor(totalMinutes / MINUTES_PER_HOUR);
    const m = totalMinutes % MINUTES_PER_HOUR;

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

const options = computed<string[]>(() => {
    const start = toMinutes(props.minTime);
    const end = toMinutes(props.maxTime);
    const result: string[] = [];

    for (let t = start; t <= end; t += props.stepMinutes) {
        result.push(toHHMM(t));
    }

    return result;
});

function select(value: string): void {
    emit('update:modelValue', value);
    menuOpen.value = false;
}

function clearValue(): void {
    emit('update:modelValue', '');
    menuOpen.value = false;
}

// 開いたら選択中の時刻が見える位置までスクロールしておく（144件あるため）。
watch(menuOpen, async (open) => {
    if (!open) {
        return;
    }

    await nextTick();
    listEl.value?.querySelector<HTMLElement>('.tf__opt--selected')?.scrollIntoView({ block: 'center' });
});
</script>

<template>
    <div class="tf" :class="{ 'tf--block': block }" v-bind="rootAttrs">
        <v-menu
            v-model="menuOpen"
            :disabled="readonly"
            :close-on-content-click="false"
            location="bottom start"
            min-width="auto"
            offset="6"
        >
            <template #activator="{ props: activatorProps }">
                <v-text-field
                    autocomplete="off"
                    v-bind="{ ...activatorProps, ...fieldAttrs }"
                    :model-value="modelValue"
                    :label="label"
                    :clearable="clearable && !readonly"
                    :hide-details="hideDetails"
                    :density="density"
                    variant="outlined"
                    prepend-inner-icon="mdi-clock-time-four-outline"
                    readonly
                    @click:clear.stop="clearValue"
                />
            </template>

            <div ref="listEl" class="tf__list">
                <button
                    v-for="opt in options"
                    :key="opt"
                    type="button"
                    class="tf__opt"
                    :class="{ 'tf__opt--selected': opt === modelValue }"
                    @click="select(opt)"
                >
                    {{ opt }}
                </button>
            </div>
        </v-menu>
    </div>
</template>

<style scoped>
.tf {
    width: var(--ark-field-time);
    max-width: 100%;
    min-width: 0;
    flex: 0 0 auto;
}

.tf--block {
    width: 100%;
    flex: 1 1 auto;
}

.tf__list {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 104px;
    max-height: 240px;
    padding: 4px;
    overflow-y: auto;
    background: rgb(var(--v-theme-surface));
    border-radius: var(--ark-radius);
    box-shadow: 0 8px 24px rgb(18 25 60 / 20%);
}

.tf__opt {
    border: 0;
    border-radius: var(--ark-radius-sm);
    background: none;
    padding: 6px 10px;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
    color: rgb(var(--v-theme-on-surface));
    text-align: left;
    cursor: pointer;
}

.tf__opt:hover {
    background: rgba(var(--v-theme-primary), 0.08);
}

.tf__opt--selected {
    background: rgb(var(--v-theme-primary));
    color: #fff;
    font-weight: 700;
}
</style>
