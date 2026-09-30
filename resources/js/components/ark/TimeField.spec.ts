import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TimeField from './TimeField.vue';

async function openMenu(field: ReturnType<typeof mount>): Promise<void> {
    await field.get('input').trigger('click');
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('TimeField', () => {
    it('選べる時刻は minTime〜maxTime の範囲だけ（営業時間から選ぶ）', async () => {
        const field = mount(TimeField, {
            props: { modelValue: '', label: '開始時刻', minTime: '10:00', maxTime: '11:00', stepMinutes: 30 },
            attachTo: document.body,
        });
        await openMenu(field);
        const options = [...document.querySelectorAll('.tf__opt')].map((el) => el.textContent?.trim());
        expect(options).toEqual(['10:00', '10:30', '11:00']);
        field.unmount();
    });

    it('読み取り専用の時は時刻の一覧を開かない', async () => {
        const field = mount(TimeField, {
            props: { modelValue: '10:00', label: '開始時刻', readonly: true },
            attachTo: document.body,
        });
        await openMenu(field);
        expect(document.querySelectorAll('.tf__opt').length).toBe(0);
        field.unmount();
    });
});
