import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MoneyField from './MoneyField.vue';

describe('MoneyField', () => {
    it('フォーカスが外れている間は3桁ごとのカンマと「円」を表示する', () => {
        const field = mount(MoneyField, { props: { modelValue: 12345, label: '価格' } });
        expect(field.get('input').element.value).toBe('12,345');
        expect(field.text()).toContain('円');
        field.unmount();
    });

    it('入力中はカンマなしで、数字以外は取り除いて数値で返す', async () => {
        const field = mount(MoneyField, { props: { modelValue: 1000, label: '価格' } });
        await field.get('input').trigger('focus');
        expect(field.get('input').element.value).toBe('1000');
        await field.get('input').setValue('3,5a0');
        expect(field.emitted('update:modelValue')?.at(-1)).toEqual([350]);
        await field.get('input').setValue('');
        expect(field.emitted('update:modelValue')?.at(-1)).toEqual([null]);
        field.unmount();
    });

    it('e や - などの数字以外のキーは入力させない', async () => {
        const field = mount(MoneyField, { props: { modelValue: null, label: '価格' } });
        const blocked = new KeyboardEvent('keydown', { key: 'e', cancelable: true, bubbles: true });
        field.get('input').element.dispatchEvent(blocked);
        expect(blocked.defaultPrevented).toBe(true);
        const allowed = new KeyboardEvent('keydown', { key: '5', cancelable: true, bubbles: true });
        field.get('input').element.dispatchEvent(allowed);
        expect(allowed.defaultPrevented).toBe(false);
        field.unmount();
    });
});
