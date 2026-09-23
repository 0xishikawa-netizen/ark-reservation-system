<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { MESSAGES } from '@/constants/messages';

interface MenuOption {
    id: number;
    name: string;
    duration_min: number;
    price: number;
    category: string | null;
    color: string;
}

const props = withDefaults(defineProps<{
    modelValue: boolean;
    services: MenuOption[];
    selectedId: number | null;
    /** 店舗全体・直近30日の実績から算出した「よく使うメニュー」のID（多い順・§7）。 */
    popularServiceIds?: number[];
}>(), {
    popularServiceIds: () => [],
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    select: [serviceId: number];
}>();

const query = ref('');
const activeCategory = ref<string | null>(null);
const searchFieldEl = ref<{ focus?: () => void } | null>(null);

const categories = computed<string[]>(() => {
    const set = new Set<string>();

    for (const service of props.services) {
        if (service.category !== null && service.category !== '') {
            set.add(service.category);
        }
    }

    return [...set];
});

const filtered = computed<MenuOption[]>(() => {
    const q = query.value.trim().toLowerCase();

    return props.services.filter((service) => {
        if (activeCategory.value !== null && service.category !== activeCategory.value) {
            return false;
        }

        if (q === '') {
            return true;
        }

        return service.name.toLowerCase().includes(q)
            || (service.category ?? '').toLowerCase().includes(q);
    });
});

const popularServices = computed<MenuOption[]>(() => {
    const byId = new Map(props.services.map((service) => [service.id, service]));

    return props.popularServiceIds
        .map((id) => byId.get(id))
        .filter((service): service is MenuOption => service !== undefined);
});

/** 検索・カテゴリ絞り込みをしていない既定表示の時だけ、店舗全体でよく使うメニューを
 * 上部にまとめて出す（§7）。絞り込み中はこの特別枠を出さず、通常の一覧だけにする。 */
const showPopularSection = computed(
    () => query.value.trim() === '' && activeCategory.value === null && popularServices.value.length > 0,
);

watch(() => props.modelValue, (open) => {
    if (open) {
        query.value = '';
        activeCategory.value = null;
        void nextTick(() => searchFieldEl.value?.focus?.());
    }
});

function money(value: number): string {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(value);
}

function choose(service: MenuOption): void {
    emit('select', service.id);
    emit('update:modelValue', false);
}

function close(): void {
    emit('update:modelValue', false);
}
</script>

<template>
    <v-dialog
        :model-value="modelValue"
        max-width="420"
        scrollable
        @update:model-value="(v) => emit('update:modelValue', v)"
    >
        <v-card class="mp" role="dialog" aria-label="メニューを選択">
            <div class="mp__head">
                <span class="mp__title">メニューを選択</span>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    aria-label="閉じる"
                    @click="close"
                />
            </div>

            <div class="mp__search">
                <v-text-field
                    ref="searchFieldEl"
                    v-model="query"
                    placeholder="メニューを検索"
                    prepend-inner-icon="mdi-magnify"
                    density="comfortable"
                    variant="outlined"
                    hide-details
                    clearable
                    aria-label="メニューを検索"
                    autofocus
                />
            </div>

            <div v-if="categories.length" class="mp__cats" role="tablist" aria-label="カテゴリで絞り込み">
                <v-chip
                    :color="activeCategory === null ? 'primary' : undefined"
                    :variant="activeCategory === null ? 'flat' : 'outlined'"
                    size="small"
                    role="tab"
                    :aria-selected="activeCategory === null"
                    @click="activeCategory = null"
                >
                    すべて
                </v-chip>
                <v-chip
                    v-for="cat in categories"
                    :key="cat"
                    :color="activeCategory === cat ? 'primary' : undefined"
                    :variant="activeCategory === cat ? 'flat' : 'outlined'"
                    size="small"
                    role="tab"
                    :aria-selected="activeCategory === cat"
                    @click="activeCategory = cat"
                >
                    {{ cat }}
                </v-chip>
            </div>

            <v-divider />

            <div class="mp__list">
                <template v-if="showPopularSection">
                    <p class="mp__section-label">
                        <v-icon icon="mdi-star" size="12" />よく使う
                    </p>
                    <button
                        v-for="service in popularServices"
                        :key="`popular-${service.id}`"
                        type="button"
                        class="mp__row"
                        :class="{ 'mp__row--selected': service.id === selectedId }"
                        @click="choose(service)"
                    >
                        <span class="mp__swatch" :style="{ background: service.color }" aria-hidden="true" />
                        <span class="mp__row-main">
                            <span class="mp__row-name">{{ service.name }}</span>
                            <span class="mp__row-sub">
                                {{ service.duration_min }}分
                                <span v-if="service.category" class="mp__row-cat">／ {{ service.category }}</span>
                            </span>
                        </span>
                        <span class="mp__row-price">{{ money(service.price) }}</span>
                        <v-icon
                            v-if="service.id === selectedId"
                            icon="mdi-check-circle"
                            size="18"
                            color="primary"
                            class="mp__row-check"
                        />
                    </button>
                    <p class="mp__section-label mp__section-label--all">すべてのメニュー</p>
                </template>

                <p v-if="filtered.length === 0" class="mp__empty">{{ MESSAGES.menu.noneMatched }}</p>
                <button
                    v-for="service in filtered"
                    :key="service.id"
                    type="button"
                    class="mp__row"
                    :class="{ 'mp__row--selected': service.id === selectedId }"
                    @click="choose(service)"
                >
                    <span class="mp__swatch" :style="{ background: service.color }" aria-hidden="true" />
                    <span class="mp__row-main">
                        <span class="mp__row-name">{{ service.name }}</span>
                        <span class="mp__row-sub">
                            {{ service.duration_min }}分
                            <span v-if="service.category" class="mp__row-cat">／ {{ service.category }}</span>
                        </span>
                    </span>
                    <span class="mp__row-price">{{ money(service.price) }}</span>
                    <v-icon
                        v-if="service.id === selectedId"
                        icon="mdi-check-circle"
                        size="18"
                        color="primary"
                        class="mp__row-check"
                    />
                </button>
            </div>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.mp {
    display: flex;
    flex-direction: column;
    max-height: 80vh;
}

.mp__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--ark-space-3) var(--ark-space-2) var(--ark-space-2) var(--ark-space-4);
}

.mp__title {
    font-size: 0.9375rem;
    font-weight: 800;
}

.mp__search {
    padding: 0 var(--ark-space-4) var(--ark-space-3);
}

.mp__cats {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 0 var(--ark-space-4) var(--ark-space-3);
}

.mp__list {
    overflow-y: auto;
    padding: var(--ark-space-2) var(--ark-space-2) var(--ark-space-3);
}

.mp__empty {
    margin: 0;
    padding: var(--ark-space-4);
    text-align: center;
    font-size: 0.8125rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.mp__section-label {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: var(--ark-space-2) var(--ark-space-2) 4px;
    font-size: 0.6875rem;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
}

.mp__section-label--all {
    margin-top: var(--ark-space-3);
    padding-top: var(--ark-space-2);
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.mp__row {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
    width: 100%;
    padding: var(--ark-space-2) var(--ark-space-2);
    border: 0;
    border-radius: var(--ark-radius);
    background: none;
    text-align: left;
    cursor: pointer;
}

.mp__row + .mp__row {
    margin-top: 2px;
}

.mp__row:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}

.mp__row--selected {
    background: rgba(var(--v-theme-primary), 0.1);
}

.mp__swatch {
    flex: 0 0 auto;
    width: 10px;
    height: 10px;
    border-radius: 999px;
}

.mp__row-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}

.mp__row-name {
    font-size: 0.8125rem;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mp__row-sub {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.mp__row-cat {
    white-space: nowrap;
}

.mp__row-price {
    flex: 0 0 auto;
    font-size: 0.8125rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.mp__row-check {
    flex: 0 0 auto;
}
</style>
