import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { changeAndReload } from './reportNavigation';

describe('changeAndReload', () => {
    it('値が変わったときだけ更新して再読込する', () => {
        const month = ref('2026-10');
        const reload = vi.fn();

        changeAndReload(month, '2026-10', reload);
        expect(reload).not.toHaveBeenCalled();

        changeAndReload(month, '2026-11', reload);
        expect(month.value).toBe('2026-11');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('再読込の Promise は待たない', () => {
        const basis = ref<'payment' | 'treatment'>('payment');
        const reload = vi.fn(() => Promise.resolve());

        changeAndReload(basis, 'treatment', reload);
        expect(basis.value).toBe('treatment');
        expect(reload).toHaveBeenCalledWith();
    });
});
