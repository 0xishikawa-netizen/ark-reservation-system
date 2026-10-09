<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { MESSAGES } from '@/constants/messages';

/** 顧客検索を開始するまでの入力待機時間。 */
const SEARCH_DEBOUNCE_MS = 300;
/** 検索結果クリックを受け付けるためのフォーカス解除待機時間。 */
const BLUR_DELAY_MS = 150;

interface SearchResult {
    user_id: number;
    name: string;
    kana: string | null;
    member_no: string;
}

withDefaults(defineProps<{
    canSearch: boolean;
    /** 新規予約で顧客がまだ選ばれていない時に、この検索欄が入口だと分かるよう赤く強調する。 */
    highlight?: boolean;
}>(), {
    highlight: false,
});

const emit = defineEmits<{
    select: [payload: { customerId: number; name: string; kana: string | null }];
}>();

const query = ref('');
const results = ref<SearchResult[]>([]);
const loading = ref(false);
const searched = ref(false);
const focused = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let blurTimer: ReturnType<typeof setTimeout> | null = null;

async function runSearch(): Promise<void> {
    const q = query.value.trim();

    if (q === '') {
        results.value = [];
        searched.value = false;

        return;
    }

    loading.value = true;

    try {
        const response = await fetch(`/admin/reservations/customer-search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        results.value = response.ok ? ((await response.json()) as SearchResult[]) : [];
    } catch {
        results.value = [];
    } finally {
        loading.value = false;
        searched.value = true;
    }
}

watch(query, () => {
    if (timer !== null) {
        clearTimeout(timer);
    }
    timer = setTimeout(() => void runSearch(), SEARCH_DEBOUNCE_MS);
});

function onFocus(): void {
    if (blurTimer !== null) {
        clearTimeout(blurTimer);
        blurTimer = null;
    }
    focused.value = true;
}

// クリックのmousedownより先にblurが発火して結果が消えてしまわないよう、少し待ってから閉じる。
function onBlur(): void {
    blurTimer = setTimeout(() => { focused.value = false; }, BLUR_DELAY_MS);
}

function selectResult(row: SearchResult): void {
    emit('select', { customerId: row.user_id, name: row.name, kana: row.kana });
    // 検索語・検索結果はあえて残す。「戻る」で検索へ戻った時に再検索なしで
    // 同じ結果を見られるようにするため（§7 検索状態保持）。閲覧中は邪魔にならないよう
    // ドロップダウンだけ閉じる。
    focused.value = false;
}

const showDropdown = computed(() => focused.value && query.value.trim() !== '');

/** 左パネル内部の「戻る」でこの検索へ戻った時、直前の検索語・結果をそのまま
 * 再表示するために呼ばれる（新しいAPI通信は発生させない）。 */
function restoreFocus(): void {
    if (query.value.trim() !== '') {
        focused.value = true;
    }
}

/** 検索以外のパネル（顧客詳細・新規予約など）へ切り替わった時、開いたままの
 * ドロップダウンが新しい内容の上に被って見えるのを防ぐ。検索語・結果自体は消さない。 */
function closeDropdown(): void {
    focused.value = false;
}

defineExpose({ restoreFocus, closeDropdown });
</script>

<template>
    <div class="psb" :class="{ 'psb--highlight': highlight && canSearch }">
        <p v-if="!canSearch" class="psb__permission">{{ MESSAGES.customer.searchForbidden }}</p>

        <v-text-field
            v-else
            v-model="query"
            :placeholder="highlight ? MESSAGES.boardUi.customerSearch.selectPlaceholder : MESSAGES.boardUi.customerSearch.searchPlaceholder"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            variant="solo"
            bg-color="white"
            flat
            clearable
            hide-details
            @focus="onFocus"
            @blur="onBlur"
        />

        <div v-show="canSearch && showDropdown" class="psb__dropdown">
            <div v-if="loading" class="psb__state">
                <v-progress-circular indeterminate size="22" color="primary" />
            </div>

            <p v-else-if="searched && results.length === 0" class="psb__muted">
                {{ MESSAGES.customer.notFound }}
            </p>

            <button
                v-for="row in results"
                :key="row.user_id"
                type="button"
                class="psb__row"
                @mousedown.prevent="selectResult(row)"
            >
                <span class="psb__row-name">{{ row.name }}</span>
                <span v-if="row.kana" class="psb__row-kana">{{ row.kana }}</span>
                <span class="psb__row-member">{{ row.member_no }}</span>
                <v-icon icon="mdi-chevron-right" size="16" class="psb__row-arrow" />
            </button>
        </div>
    </div>
</template>

<style scoped>
/* 予約台帳の左パネル上部に常時表示する顧客検索（Peak Manager 参考・§8）。 */
.psb {
    position: relative;
    flex: 0 0 auto;
    padding: var(--ark-space-2);
    background: rgb(var(--v-theme-primary));
}

/* 顧客未選択の新規予約中は、ここが入口だと一目で分かるよう赤い枠で強調する。 */
.psb--highlight :deep(.v-field) {
    box-shadow: 0 0 0 2px rgb(var(--v-theme-error));
}

.psb--highlight :deep(.v-field__input::placeholder) {
    color: rgb(var(--v-theme-error));
    opacity: 1;
}

.psb--highlight :deep(.v-field__prepend-inner .v-icon) {
    color: rgb(var(--v-theme-error));
}

.psb__permission {
    margin: 0;
    padding: var(--ark-space-2);
    font-size: 0.6875rem;
    color: rgb(255 255 255 / 0.75);
}

/* パネル内の入力欄（40px）と高さを揃える。 */
.psb :deep(.v-field__input) {
    min-height: 40px;
    padding-top: 8px;
    padding-bottom: 8px;
    font-size: 0.8125rem;
}

.psb :deep(.v-field__prepend-inner) {
    padding-top: 8px;
}

.psb__dropdown {
    position: absolute;
    left: var(--ark-space-2);
    right: var(--ark-space-2);
    top: calc(100% - 2px);
    z-index: 10;
    max-height: 320px;
    overflow-y: auto;
    padding: var(--ark-space-2);
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
    border-radius: var(--ark-radius);
    box-shadow: 0 8px 24px rgb(18 25 60 / 20%);
}

.psb__state {
    display: flex;
    justify-content: center;
    padding: var(--ark-space-3) 0;
}

.psb__muted {
    margin: 0;
    padding: var(--ark-space-2);
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.psb__row {
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
    width: 100%;
    padding: var(--ark-space-2) var(--ark-space-1);
    border: 0;
    border-radius: var(--ark-radius);
    background: none;
    text-align: left;
    cursor: pointer;
}

.psb__row + .psb__row {
    margin-top: 2px;
}

.psb__row:hover {
    background: rgba(var(--v-theme-primary), 0.08);
}

.psb__row-name {
    font-size: 0.75rem;
    font-weight: 700;
}

.psb__row-kana {
    font-size: 0.625rem;
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.psb__row-member {
    margin-left: auto;
    font-size: 0.625rem;
    font-variant-numeric: tabular-nums;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.psb__row-arrow {
    color: rgba(var(--v-theme-on-surface), 0.68);
}
</style>
