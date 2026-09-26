import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ArkMonthCalendar from './ArkMonthCalendar.vue';

describe('ArkMonthCalendar', () => {
    it('選択中の月を示し、12か月から選択できる', async () => {
        const calendar = mount(ArkMonthCalendar, { props: { modelValue: '2026-09' } });
        expect(calendar.findAll('[data-testid^="calendar-month-"]')).toHaveLength(12);
        expect(calendar.get('[data-testid="calendar-month-2026-09"]').attributes('aria-pressed')).toBe('true');
        await calendar.get('[data-testid="calendar-month-2026-10"]').trigger('click');
        expect(calendar.emitted('update:modelValue')?.[0]).toEqual(['2026-10']);
    });

    it('前後の年へ移動し、選べる範囲を2000〜2100年に制限する', async () => {
        const calendar = mount(ArkMonthCalendar, { props: { modelValue: '2000-01' } });
        expect(calendar.get('[aria-label="前の年"]').attributes('disabled')).toBeDefined();
        await calendar.get('[aria-label="次の年"]').trigger('click');
        expect(calendar.text()).toContain('2001年');
        expect(calendar.find('[data-testid="calendar-month-2001-12"]').exists()).toBe(true);
        await calendar.setProps({ modelValue: '2100-12' });
        expect(calendar.text()).toContain('2100年');
        expect(calendar.get('[aria-label="次の年"]').attributes('disabled')).toBeDefined();
    });

    it('今月ボタンはAsia/Tokyoの年月を返す', async () => {
        const calendar = mount(ArkMonthCalendar, { props: { modelValue: '2020-01' } });
        expect(calendar.get('.ark-month-cal__current').text()).toBe('今月');
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Tokyo', year: 'numeric', month: 'numeric',
        }).formatToParts(new Date());
        const year = Number(parts.find((part) => part.type === 'year')?.value);
        const month = Number(parts.find((part) => part.type === 'month')?.value);
        await calendar.get('.ark-month-cal__current').trigger('click');
        expect(calendar.emitted('update:modelValue')?.[0]).toEqual([`${year}-${String(month).padStart(2, '0')}`]);
    });
});
