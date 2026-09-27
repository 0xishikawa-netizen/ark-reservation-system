<script setup lang="ts">
/**
 * Reports 共通のテーブル枠。中身（thead / tbody / tfoot）は各画面がスロットで書き、
 * 見た目（固定ヘッダー・固定列・数値右寄せ・グループ区切り・縞・hover・合計行・空表示）は
 * 下の共通クラスで揃える。各画面で個別の table CSS を増やさないこと。
 *
 * 使えるクラス:
 *   th/td.num            数値（右寄せ・等幅数字）
 *   th/td.is-sticky      左端に固定する1列目（幅は --report-sticky-width）
 *   th/td.is-sticky-2    1列目の右に固定する2列目
 *   th/td.group-start    列グループの区切り線（左側）
 *   thead tr.group-row   列グループ見出し行
 *   tbody tr.row-muted   未来日など控えめに表示する行
 *   tbody tr.row-alert   休業日など注意を促す行
 *   tbody tr.row-group-start 日付・スタッフのまとまりの先頭行（上に区切り線）
 *   td.empty-cell        データなしの行（colspan で全幅に）
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    loading?: boolean;
    /** 縦スクロール領域の最大高さ。ヘッダーは固定される。'none' で表内の縦スクロールをなくす。 */
    maxHeight?: string;
    /** 横スクロールを出し始める表の最小幅。 */
    minWidth?: string;
    /** 1列目（固定列）の幅。 */
    stickyWidth?: string;
    /**
     * 表内で縦スクロールさせない表（maxHeight='none'）で、列見出しをページのスクロールに追従させる。
     * 横スクロールは表の枠内に残すため CSS の sticky は使えず（枠がスクロール基準になる）、
     * 同じ thead を管理画面ヘッダーの直下まで translateY で下げる。見出しと列は同じ表なので横にずれない。
     */
    pageStickyHeader?: boolean;
}>(), {
    loading: false,
    maxHeight: '70vh',
    minWidth: '100%',
    stickyWidth: '96px',
    pageStickyHeader: false,
});

const wrap = ref<HTMLElement | null>(null);
const stuck = ref(false);
let frame = 0;

/** 画面上部に固定されている管理画面ヘッダー（v-app-bar）の下端。無ければ 0。 */
const fixedHeaderBottom = (): number => {
    const bar = document.querySelector<HTMLElement>('.v-app-bar');
    return bar ? Math.max(bar.getBoundingClientRect().bottom, 0) : 0;
};

const updateStickyHeader = (): void => {
    frame = 0;
    const element = wrap.value;
    const thead = element?.querySelector('thead');
    if (!element || !thead) return;
    const rect = element.getBoundingClientRect();
    const footHeight = element.querySelector('tfoot')?.getBoundingClientRect().height ?? 0;
    // 見出しは表の上端より上へは出さず、合計行の手前で止める（表の外へはみ出さない）。
    const maxOffset = Math.max(rect.height - thead.getBoundingClientRect().height - footHeight, 0);
    // 枠線（clientTop）の分も差し引き、管理画面ヘッダーの下端にすき間なく付ける（下の行が透けない）。
    const offset = Math.min(Math.max(fixedHeaderBottom() - rect.top - element.clientTop, 0), maxOffset);
    element.style.setProperty('--report-page-sticky-offset', `${offset}px`);
    stuck.value = offset > 0;
};

const scheduleUpdate = (): void => {
    if (frame === 0) frame = window.requestAnimationFrame(updateStickyHeader);
};

onMounted(() => {
    if (!props.pageStickyHeader) return;
    window.addEventListener('scroll', scheduleUpdate, { passive: true });
    window.addEventListener('resize', scheduleUpdate, { passive: true });
    updateStickyHeader();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', scheduleUpdate);
    window.removeEventListener('resize', scheduleUpdate);
    if (frame !== 0) window.cancelAnimationFrame(frame);
});
</script>

<template>
    <div
        ref="wrap"
        class="ark-report-table-wrap"
        :class="{ 'is-loading': loading, 'is-page-sticky': pageStickyHeader, 'is-stuck': stuck }"
        :style="{ maxHeight, '--report-sticky-width': stickyWidth }"
        :aria-busy="loading"
    >
        <table v-bind="$attrs" class="ark-report-table" :style="{ minWidth }">
            <slot />
        </table>
    </div>
</template>

<style>
.ark-report-table-wrap {
    position: relative;
    overflow: auto;
    border: 1px solid #e3e7ee;
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    transition: opacity 0.15s ease;
}

.ark-report-table-wrap.is-loading {
    opacity: 0.55;
}

.ark-report-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
    line-height: 1.45;
}

.ark-report-table th,
.ark-report-table td {
    padding: 7px 12px;
    border-bottom: 1px solid #edf0f4;
    background: rgb(var(--v-theme-surface));
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.ark-report-table tbody th {
    font-weight: 600;
}

.ark-report-table .num {
    text-align: right;
}

/* 見出しは常に上に固定（2段見出しでも thead ごと固定するのでずれない）。 */
.ark-report-table thead {
    position: sticky;
    top: 0;
    z-index: 3;
}

.ark-report-table thead th {
    background: #f5f7fa;
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.75rem;
    font-weight: 700;
    border-bottom: 1px solid #dfe4ec;
}

.ark-report-table thead tr.group-row th {
    padding-block: 5px;
    color: rgb(var(--v-theme-primary));
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    text-align: center;
    border-bottom: 1px solid #e6eaf0;
}

/* 2段見出しで縦に結合した列見出し（日・スタッフなど）は通常の見出しと同じ見た目にする。 */
.ark-report-table thead tr.group-row th[rowspan] {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.75rem;
    letter-spacing: 0;
    text-align: left;
    vertical-align: bottom;
    border-bottom: 1px solid #dfe4ec;
}

.ark-report-table thead tr.group-row th[rowspan].num {
    text-align: right;
}

.ark-report-table thead small {
    color: rgba(var(--v-theme-on-surface), 0.5);
    font-size: 0.6875rem;
    font-weight: 600;
}

.ark-report-table .group-start {
    border-left: 1px solid #dfe4ec;
}

/*
 * ページスクロール追従の見出し（pageStickyHeader）。thead ごと下げるので2段見出しも崩れない。
 * 管理画面ヘッダー（v-app-bar）より下、表の本文・固定列より上に重ねる。背景は不透明（th の背景色）。
 */
.ark-report-table-wrap.is-page-sticky thead {
    z-index: 5;
    transform: translateY(var(--report-page-sticky-offset, 0px));
    will-change: transform;
}

.ark-report-table-wrap.is-page-sticky.is-stuck thead tr:last-child th {
    box-shadow: 0 1px 0 #dfe4ec, 0 2px 4px rgba(15, 23, 42, 0.06);
}

.ark-report-table-wrap.is-page-sticky.is-stuck thead tr:last-child th.is-sticky {
    box-shadow: inset -1px 0 0 #dfe4ec, 0 1px 0 #dfe4ec, 0 2px 4px rgba(15, 23, 42, 0.06);
}

/* 左固定列 */
.ark-report-table .is-sticky,
.ark-report-table .is-sticky-2 {
    position: sticky;
    z-index: 2;
}

.ark-report-table .is-sticky {
    left: 0;
    min-width: var(--report-sticky-width);
    max-width: var(--report-sticky-width);
}

.ark-report-table .is-sticky-2 {
    left: var(--report-sticky-width);
    box-shadow: inset -1px 0 0 #dfe4ec;
}

.ark-report-table .is-sticky:not(:has(+ .is-sticky-2)) {
    box-shadow: inset -1px 0 0 #dfe4ec;
}

.ark-report-table thead .is-sticky,
.ark-report-table thead .is-sticky-2 {
    z-index: 4;
}

/* 縞・hover（固定列も同じ色にする） */
.ark-report-table tbody tr:nth-child(even) > * {
    background: #fafbfd;
}

.ark-report-table tbody tr:hover > * {
    background: #f0f3f9;
}

.ark-report-table tbody tr.row-muted > * {
    color: rgba(var(--v-theme-on-surface), 0.5);
}

/* まとまりの見出し（固定列の日付・スタッフ）は控えめ行でも読めるようにする。 */
.ark-report-table tbody tr.row-muted > th.is-sticky,
.ark-report-table tbody tr.row-muted > th.is-sticky-2 {
    color: rgb(var(--v-theme-on-surface));
}

.ark-report-table tbody tr.row-alert > * {
    background: #fff7f4;
}

.ark-report-table tbody tr.row-group-start > * {
    border-top: 1px solid #d5dbe5;
}

/* 合計行は下に固定 */
.ark-report-table tfoot th,
.ark-report-table tfoot td {
    position: sticky;
    bottom: 0;
    z-index: 2;
    background: #f3f6fa;
    font-weight: 700;
    border-top: 1px solid #d5dbe5;
    border-bottom: 0;
}

.ark-report-table tfoot .is-sticky,
.ark-report-table tfoot .is-sticky-2 {
    z-index: 3;
}

.ark-report-table td.empty-cell {
    padding: 28px 12px;
    color: rgba(var(--v-theme-on-surface), 0.55);
    text-align: center;
    white-space: normal;
}

.ark-report-table tbody tr:last-child > * {
    border-bottom: 0;
}

/* セル内の補足（出勤mの「実勤怠／予定代用」など） */
.ark-report-table .cell-note {
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 999px;
    background: #eef1f6;
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.6875rem;
    font-weight: 600;
}

.ark-report-table .cell-tag {
    display: inline-block;
    margin-left: 4px;
    padding: 0 5px;
    border-radius: 4px;
    background: #fdecea;
    color: #b42318;
    font-size: 0.6875rem;
    font-weight: 700;
}
</style>
