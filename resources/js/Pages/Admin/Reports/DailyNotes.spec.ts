import { router } from '@inertiajs/vue3';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MonthField from '@/components/ark/MonthField.vue';
import DailyNotes from './DailyNotes.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { get: vi.fn(), put: vi.fn() },
}));

const day = { business_date: '2026-09-15', day: 15, weekday: '火', business_condition: '', reflection: '' };
let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.clearAllMocks(); });

function render(editable = true): VueWrapper {
    wrapper = mount(DailyNotes, { props: {
        month: '2026-09', days: [day], editable, indexEndpoint: '/admin/reports/daily-notes',
    } });
    return wrapper;
}

describe('日報画面', () => {
    it('未保存状態を示し、1日分だけ保存して成功を表示する', async () => {
        const page = render();
        const textareas = page.findAll('textarea');
        await textareas[0].setValue('午前は静か\n夕方に集中');
        await textareas[1].setValue('次回予約の案内を改善');
        expect(page.text()).toContain('未保存の変更があります');
        await page.find('button').trigger('click');
        expect(router.put).toHaveBeenCalledWith('/admin/reports/daily-notes/2026-09-15', {
            business_condition: '午前は静か\n夕方に集中', reflection: '次回予約の案内を改善',
        }, expect.any(Object));
        const options = vi.mocked(router.put).mock.calls[0][2];
        options?.onSuccess?.({} as never);
        options?.onFinish?.({} as never);
        await page.vm.$nextTick();
        expect(page.text()).toContain('保存しました。');
        expect(page.text()).not.toContain('未保存の変更があります');
    });

    it('閲覧権限のみなら編集・保存できない', () => {
        const page = render(false);
        expect(page.findAll('textarea').every((element) => element.attributes('readonly') !== undefined)).toBe(true);
        expect(page.find('button').exists()).toBe(false);
        expect(page.text()).toContain('閲覧のみ可能です');
    });

    it('年月変更は日報ページを再読込する', async () => {
        const page = render();
        expect(page.find('input[type="month"]').exists()).toBe(false);
        expect(page.find('input[readonly]').exists()).toBe(true);
        expect(page.find('.ark-month-field').exists()).toBe(true);
        page.getComponent(MonthField).vm.$emit('update:modelValue', '2026-10');
        expect(router.get).toHaveBeenCalledWith('/admin/reports/daily-notes', { month: '2026-10' }, { preserveState: false });
    });
});
