import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ArkCalendar from './ArkCalendar.vue';

describe('ArkCalendar', () => {
    it('当日へ移動するボタンを「今日」と表示する', () => {
        const calendar = mount(ArkCalendar, { props: { modelValue: '2026-09-15' } });
        expect(calendar.get('.ark-cal__today').text()).toBe('今日');
    });
});
