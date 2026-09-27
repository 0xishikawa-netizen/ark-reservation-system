import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ReportTable from './ReportTable.vue';

const rect = (top: number, height: number, bottom = top + height): DOMRect => ({ top, height, bottom, left: 0, right: 0, width: 0, x: 0, y: top, toJSON: () => ({}) });

describe('ReportTable のページ追従見出し', () => {
    afterEach(() => { vi.restoreAllMocks(); document.body.innerHTML = ''; });

    function render(wrapTop: number) {
        const bar = document.createElement('header');
        bar.className = 'v-app-bar';
        bar.getBoundingClientRect = () => rect(0, 61);
        document.body.appendChild(bar);
        vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback: FrameRequestCallback) => { callback(0); return 1; });
        const table = mount(ReportTable, {
            props: { maxHeight: 'none', pageStickyHeader: true, minWidth: '1800px' },
            slots: { default: '<thead><tr><th class="is-sticky">日</th><th>現金</th></tr></thead><tbody><tr><th class="is-sticky">1日</th><td>0円</td></tr></tbody><tfoot><tr><th>合計</th><td>0円</td></tr></tfoot>' },
            attachTo: document.body,
        });
        const wrap = table.get('.ark-report-table-wrap').element as HTMLElement;
        wrap.getBoundingClientRect = () => rect(wrapTop, 1000);
        (wrap.querySelector('thead') as HTMLElement).getBoundingClientRect = () => rect(0, 60);
        (wrap.querySelector('tfoot') as HTMLElement).getBoundingClientRect = () => rect(0, 40);
        window.dispatchEvent(new Event('scroll'));
        return { table, wrap };
    }

    it('表内で縦スクロールさせず、横スクロールと左固定列はそのまま', () => {
        const { table, wrap } = render(600);
        expect(wrap.style.maxHeight).toBe('none');
        expect((table.get('table').element as HTMLElement).style.minWidth).toBe('1800px');
        expect(wrap.classList.contains('is-page-sticky')).toBe(true);
        expect(table.findAll('.is-sticky')).toHaveLength(2);
        table.unmount();
    });

    it('ページ上端にある時は動かさず、下へ進むと見出しを管理画面ヘッダーの直下まで下げる', async () => {
        const top = render(600);
        expect(top.wrap.style.getPropertyValue('--report-page-sticky-offset')).toBe('0px');
        expect(top.wrap.classList.contains('is-stuck')).toBe(false);
        top.table.unmount();
        document.body.innerHTML = '';

        const scrolled = render(-300);
        await scrolled.table.vm.$nextTick();
        // ヘッダー下端 61px - 表の上端 -300px = 361px 下げる。
        expect(scrolled.wrap.style.getPropertyValue('--report-page-sticky-offset')).toBe('361px');
        expect(scrolled.wrap.classList.contains('is-stuck')).toBe(true);
        scrolled.table.unmount();
    });

    it('表の下端を越えて見出しを下げない（合計行の手前で止める）', () => {
        const { table, wrap } = render(-5000);
        // 表の高さ 1000 - 見出し 60 - 合計行 40 = 900px が上限。
        expect(wrap.style.getPropertyValue('--report-page-sticky-offset')).toBe('900px');
        table.unmount();
    });
});
