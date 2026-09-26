import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import YearField from './YearField.vue';

describe('YearField', () => {
    it('「2026年」と表示し、ブラウザ標準の number input を使わない', () => {
        const field = mount(YearField, { props: { modelValue: 2026, label: '対象年' } });
        expect(field.get('input').element.value).toBe('2026年');
        expect(field.get('input').attributes('readonly')).toBeDefined();
        expect(field.find('input[type="number"]').exists()).toBe(false);
        field.unmount();
    });

    it('ポップアップの年を選ぶと数値で返す', async () => {
        const field = mount(YearField, { props: { modelValue: 2026, label: '対象年' }, attachTo: document.body });
        await field.get('input').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        const year = document.querySelector<HTMLButtonElement>('[data-testid="calendar-year-2027"]');
        expect(year).not.toBeNull();
        year?.click();
        expect(field.emitted('update:modelValue')?.[0]).toEqual([2027]);
        field.unmount();
    });
});
