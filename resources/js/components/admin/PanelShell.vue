<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import PanelHeader from '@/components/admin/PanelHeader.vue';

/** スクロール有無を判定する際に許容する端数ピクセル。 */
const SCROLLABLE_TOLERANCE_PX = 1;

withDefaults(defineProps<{
    title: string;
    icon?: string | null;
    showBack?: boolean;
    /** 本文の余白を自前で持つコンポーネント（区切り線が端まで届く一覧など）向けに false にできる。 */
    bodyPadding?: boolean;
}>(), {
    icon: null,
    showBack: false,
    bodyPadding: true,
});

const emit = defineEmits<{
    close: [];
    back: [];
}>();

/**
 * 本文に実際にスクロールが出ている時だけ、スクロールを外（ページ）へ伝えないようにする。
 * 常に overscroll-behavior: contain にしていると、中身が短くてスクロールが無い時でも
 * パネル上のホイール操作が止まり、ページがスクロールしづらくなっていた。
 */
const bodyEl = ref<HTMLElement | null>(null);
const bodyScrollable = ref(false);
let bodyResizeObserver: ResizeObserver | null = null;

function syncBodyScrollable(): void {
    const el = bodyEl.value;

    bodyScrollable.value = el !== null && el.scrollHeight > el.clientHeight + SCROLLABLE_TOLERANCE_PX;
}

onMounted(() => {
    syncBodyScrollable();

    if (typeof ResizeObserver === 'undefined' || bodyEl.value === null) {
        return;
    }

    bodyResizeObserver = new ResizeObserver(syncBodyScrollable);
    bodyResizeObserver.observe(bodyEl.value);

    // 中身の高さが変わった時（入力欄の追加・検索結果など）も測り直す。
    for (const child of Array.from(bodyEl.value.children)) {
        bodyResizeObserver.observe(child);
    }
});

onBeforeUnmount(() => {
    bodyResizeObserver?.disconnect();
    bodyResizeObserver = null;
});
</script>

<template>
    <div class="panel-shell">
        <PanelHeader
            :title="title"
            :icon="icon"
            :show-back="showBack"
            @close="emit('close')"
            @back="emit('back')"
        />

        <!-- フッターは本文と同じスクロール領域に入れて sticky で下端に留める。
             外に出すとスクロールバーの幅ぶん本文より広くなり、枠がずれて見えるため。 -->
        <div
            ref="bodyEl"
            class="panel-shell__body"
            :class="{ 'panel-shell__body--scrollable': bodyScrollable }"
        >
            <div class="panel-shell__sheet" :class="{ 'panel-shell__sheet--flush': !bodyPadding }">
                <slot />
            </div>

            <div v-if="$slots.footer" class="panel-shell__footer">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * 台帳サイドパネル（顧客詳細／顧客検索／新規予約／予定作成／予定詳細）の共通シェル（§8-9）。
 * すべてのモードで同じヘッダー位置・幅・高さ・余白・スクロール挙動にする（全モード同じ大きさ）。
 * Peak Manager 参考：外枠は ARK navy、中身は白いシート1枚に載せて視認性を上げる。
 * 幅そのものは親（Schedule/Index.vue の .board-layout__panel、台帳を広く見せるため300px・レスポンシブ）が正。
 */
.panel-shell {
    display: flex;
    flex-direction: column;
    width: 100%;
    min-width: 0;
    height: 100%;
    background: rgb(var(--v-theme-primary));
    overflow: hidden;
}

.panel-shell__body {
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    /* スクロールバーが出る時／出ない時で中身の実質幅が変わって見えるのを防ぐため、
       スクロールの有無に関わらず常にバー分の余白を確保しておく（§対策）。 */
    scrollbar-gutter: stable;
    padding: var(--ark-space-2);
    display: flex;
    flex-direction: column;
    /* 横は本文もフッターも同じ幅いっぱいに。縦は中身の実際の高さに追従させる
       （伸ばすと、長い内容が白いシートの外へはみ出して読めなくなる）。 */
    align-items: stretch;
    /* 既定のスクロールバーはOS設定によって操作中しか出ず、どこまで進んだか分からない。
       常に見える細いバーにして、今の位置がひと目で分かるようにする。 */
    scrollbar-width: thin;
    scrollbar-color: rgb(255 255 255 / 0.45) transparent;
}

/* 本文がスクロールできる時だけ、端まで行ってもページ側へスクロールを伝えない。 */
.panel-shell__body--scrollable {
    overscroll-behavior: contain;
}

.panel-shell__body::-webkit-scrollbar {
    width: 8px;
}

.panel-shell__body::-webkit-scrollbar-track {
    background: transparent;
}

.panel-shell__body::-webkit-scrollbar-thumb {
    background: rgb(255 255 255 / 0.45);
    border-radius: 999px;
}

.panel-shell__body::-webkit-scrollbar-thumb:hover {
    background: rgb(255 255 255 / 0.65);
}

.panel-shell__sheet {
    flex: 1 0 auto;
    min-width: 0;
    padding: var(--ark-space-4);
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-3);
    background: rgb(var(--v-theme-surface));
    border-radius: var(--ark-radius);
    box-shadow: 0 1px 3px rgb(18 25 60 / 12%);
}

/* Vuetify の .v-input は flex:1 1 auto のため、シートに余白があると縦に伸びて
   入力欄の高さがバラバラになる。中身は常に自然な高さで上から積む。 */
.panel-shell__sheet > * {
    flex: 0 0 auto;
}

.panel-shell__sheet--flush {
    padding: 0;
    gap: 0;
    display: block;
}

.panel-shell__footer {
    position: sticky;
    bottom: 0;
    z-index: 1;
    flex: 0 0 auto;
    min-width: 0;
    margin-top: var(--ark-space-2);
    padding: var(--ark-space-3) var(--ark-space-4);
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    /* 本文をスクロールした時にフッターとの境目が分かるよう、上に影と線を出して浮かせる。 */
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.1);
    box-shadow:
        0 -6px 12px rgb(18 25 60 / 16%),
        0 1px 3px rgb(18 25 60 / 12%);
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-2);
}

/*
 * 左パネルの入力欄・選択欄・チェックボックス・テキストエリアの文字サイズをそろえる。
 * Vuetify の既定（16px）のままだと、パネル内の他の項目（11〜13px）より大きく浮いて見えるため、
 * 本文と同じ 13px 基準にする（ラベル・ヒント・エラーも比例して小さく）。
 */
.panel-shell__sheet :deep(.v-field),
.panel-shell__sheet :deep(.v-field__input),
.panel-shell__sheet :deep(.v-select__selection),
.panel-shell__sheet :deep(.v-field input),
.panel-shell__sheet :deep(.v-field textarea),
.panel-shell__sheet :deep(.v-label.v-field-label) {
    font-size: 0.8125rem;
}

.panel-shell__sheet :deep(.v-field-label--floating) {
    font-size: 0.6875rem;
}

.panel-shell__sheet :deep(.v-field__input) {
    min-height: 38px;
    padding-top: 8px;
    padding-bottom: 8px;
}

.panel-shell__sheet :deep(.v-label),
.panel-shell__sheet :deep(.v-selection-control .v-label) {
    font-size: 0.8125rem;
    opacity: 1;
}

.panel-shell__sheet :deep(.v-messages),
.panel-shell__sheet :deep(.v-messages__message) {
    font-size: 0.6875rem;
    line-height: 1.4;
}

.panel-shell__sheet :deep(.v-chip) {
    font-size: 0.75rem;
}

.panel-shell__sheet :deep(.v-selection-control) {
    --v-selection-control-size: 32px;
}
</style>
