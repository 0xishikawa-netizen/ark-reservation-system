import { DOMWrapper, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import MenuPicker from './MenuPicker.vue';

/**
 * v-dialog の中身は document.body 直下へ teleport されるため、`wrapper.find()`
 * （元のマウント位置配下しか見ない）では見つからない。body 全体を対象にする。
 */
function body(): DOMWrapper<HTMLElement> {
    return new DOMWrapper(document.body);
}

const services = [
    { id: 1, name: 'パーソナルトレーニング 60分', duration_min: 60, price: 8800, category: 'パーソナル', color: '#1A2653' },
    { id: 2, name: 'パーソナルトレーニング 90分', duration_min: 90, price: 13200, category: 'パーソナル', color: '#1A2653' },
    { id: 3, name: 'コンディショニング 30分', duration_min: 30, price: 4400, category: 'コンディショニング', color: '#0087C5' },
    { id: 4, name: '整体・骨格調整 45分', duration_min: 45, price: 6600, category: '整体', color: '#5B6470' },
];

let activeWrapper: VueWrapper | null = null;

afterEach(() => {
    // v-dialog は document.body 直下へ teleport するため、後片付けしないと
    // 次のテストの body() 検索に前回分まで混ざってしまう。
    activeWrapper?.unmount();
    activeWrapper = null;
    document.body.innerHTML = '';
});

async function mountPicker(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(MenuPicker, {
        props: {
            modelValue: true,
            services,
            selectedId: null,
            popularServiceIds: [],
            ...overrides,
        },
        attachTo: document.body,
    });
    activeWrapper = wrapper;
    // v-dialog は teleport + transition のため、内容が DOM へ入るまで1tick待つ。
    await nextTick();
    await nextTick();

    return wrapper;
}

describe('MenuPicker', () => {
    it('lists every service when no search/category filter is active', async () => {
        await mountPicker();

        expect(body().findAll('.mp__row')).toHaveLength(services.length);
    });

    it('filters the list by name when searching', async () => {
        await mountPicker();

        await body().find('input').setValue('整体');
        await nextTick();

        const rows = body().findAll('.mp__row');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('整体・骨格調整');
    });

    it('filters the list by category chip', async () => {
        await mountPicker();
        const chips = body().findAll('.mp__cats .v-chip');
        // 先頭は「すべて」、以降がカテゴリ（パーソナル/コンディショニング/整体）。
        const personalChip = chips.find((c) => c.text() === 'パーソナル');

        expect(personalChip).toBeDefined();
        await personalChip!.trigger('click');
        await nextTick();

        const rows = body().findAll('.mp__row');
        expect(rows).toHaveLength(2);
        expect(rows.every((r) => r.text().includes('パーソナルトレーニング'))).toBe(true);
    });

    it('shows a "よく使う" section listing the popular services, followed by the full list', async () => {
        await mountPicker({ popularServiceIds: [3, 1] });

        expect(body().text()).toContain('よく使う');
        expect(body().text()).toContain('すべてのメニュー');

        const rows = body().findAll('.mp__row');
        // 「よく使う」2件 + 全メニュー4件 = 6行。
        expect(rows).toHaveLength(6);
        // よく使うの先頭行は popularServiceIds の順（id:3 → id:1）。
        expect(rows[0].text()).toContain('コンディショニング 30分');
        expect(rows[1].text()).toContain('パーソナルトレーニング 60分');
    });

    it('hides the "よく使う" section while searching', async () => {
        await mountPicker({ popularServiceIds: [3, 1] });
        await body().find('input').setValue('整体');
        await nextTick();

        expect(body().text()).not.toContain('よく使う');
    });

    it('emits select with the service id and closes the dialog when a row is clicked', async () => {
        const wrapper = await mountPicker();
        const rows = body().findAll('.mp__row');
        const target = rows.find((r) => r.text().includes('整体・骨格調整'));

        await target!.trigger('click');

        expect(wrapper.emitted('select')).toEqual([[4]]);
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false]);
    });

    it('emits update:modelValue(false) when the close button is clicked', async () => {
        const wrapper = await mountPicker();
        const closeBtn = body().find('button[aria-label="閉じる"]');

        await closeBtn.trigger('click');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false]);
    });

    it('shows an empty state when nothing matches the search', async () => {
        await mountPicker();
        await body().find('input').setValue('該当しない検索語');
        await nextTick();

        expect(body().find('.mp__empty').exists()).toBe(true);
    });
});
