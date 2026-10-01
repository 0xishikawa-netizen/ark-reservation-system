import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import PanelCustomerSearchBar from './PanelCustomerSearchBar.vue';

const searchResults = [
    { user_id: 1, name: '林 愛', kana: 'ハヤシ アイ', member_no: 'ARK000146' },
];

async function search(wrapper: ReturnType<typeof mount>, term: string): Promise<void> {
    await wrapper.find('input').setValue(term);
    await wrapper.find('input').trigger('focus');
    vi.advanceTimersByTime(300);
    await flushPromises();
}

async function flushPromises(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
    await nextTick();
}

describe('PanelCustomerSearchBar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => searchResults,
        }));
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('shows a permission message instead of the input when canSearch is false', () => {
        const wrapper = mount(PanelCustomerSearchBar, { props: { canSearch: false } });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.text()).toContain('顧客検索を行う権限がありません');
    });

    it('fetches and shows results after the debounce window', async () => {
        const wrapper = mount(PanelCustomerSearchBar, { props: { canSearch: true } });

        await search(wrapper, '林');

        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('/admin/reservations/customer-search?q=%E6%9E%97'),
            expect.any(Object),
        );
        expect(wrapper.text()).toContain('林 愛');
        expect(wrapper.text()).toContain('ARK000146');
    });

    it('keeps the search term and results after selecting a result (§7 検索状態保持)', async () => {
        const wrapper = mount(PanelCustomerSearchBar, { props: { canSearch: true } });
        await search(wrapper, '林');

        await wrapper.find('.psb__row').trigger('mousedown');

        expect(wrapper.emitted('select')).toEqual([
            [{ customerId: 1, name: '林 愛', kana: 'ハヤシ アイ' }],
        ]);
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('林');
        // ドロップダウンは閉じるが、検索結果自体は保持している。
        expect(wrapper.find('.psb__dropdown').attributes('style')).toContain('display: none');
        expect(wrapper.text()).toContain('林 愛');
    });

    it('restoreFocus() reopens the dropdown without re-fetching when a query is present', async () => {
        const wrapper = mount(PanelCustomerSearchBar, { props: { canSearch: true } });
        await search(wrapper, '林');
        await wrapper.find('.psb__row').trigger('mousedown');
        expect(wrapper.find('.psb__dropdown').attributes('style')).toContain('display: none');

        (fetch as ReturnType<typeof vi.fn>).mockClear();
        (wrapper.vm as unknown as { restoreFocus: () => void }).restoreFocus();
        await nextTick();

        // isVisible() は document に接続されていない要素だと jsdom の制約で常に false
        // 判定になってしまうため、v-show が付け外しする style 属性を直接見る。
        expect(wrapper.find('.psb__dropdown').attributes('style') ?? '').not.toContain('display: none');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('closeDropdown() hides the dropdown without clearing the query', async () => {
        const wrapper = mount(PanelCustomerSearchBar, { props: { canSearch: true } });
        await search(wrapper, '林');

        (wrapper.vm as unknown as { closeDropdown: () => void }).closeDropdown();
        await nextTick();

        expect(wrapper.find('.psb__dropdown').attributes('style')).toContain('display: none');
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('林');
    });
});
