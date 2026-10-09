import { beforeEach, describe, expect, it, vi } from 'vitest';

const router = vi.hoisted(() => ({ patch: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));

import { useMasterActiveToggle } from './masterActive';

type PatchOptions = { preserveScroll: boolean; onFinish: () => void };

describe('useMasterActiveToggle', () => {
    beforeEach(() => router.patch.mockReset());

    it('切り替え後の値を {basePath}/{id}/active へ送る', () => {
        const { toggle } = useMasterActiveToggle('/admin/booths');
        toggle({ id: 7, is_active: true }, false);

        expect(router.patch).toHaveBeenCalledWith('/admin/booths/7/active', { active: false }, expect.objectContaining({ preserveScroll: true }));
    });

    it('送信中は同じ行の連打を送らず、完了後は再び送れる', () => {
        const { toggle, isPending } = useMasterActiveToggle('/admin/services');
        toggle({ id: 3, is_active: false }, true);
        toggle({ id: 3, is_active: false }, true);

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(isPending(3)).toBe(true);

        const options = router.patch.mock.calls[0][2] as PatchOptions;
        options.onFinish();

        expect(isPending(3)).toBe(false);
        toggle({ id: 3, is_active: false }, true);
        expect(router.patch).toHaveBeenCalledTimes(2);
    });

    it('現在値と同じ値・null では送らない', () => {
        const { toggle } = useMasterActiveToggle('/admin/ticket-products');
        toggle({ id: 1, is_active: true }, true);
        toggle({ id: 1, is_active: true }, null);

        expect(router.patch).not.toHaveBeenCalled();
    });

    it('別の行は互いに妨げない', () => {
        const { toggle } = useMasterActiveToggle('/admin/membership-plans');
        toggle({ id: 1, is_active: true }, false);
        toggle({ id: 2, is_active: true }, false);

        expect(router.patch).toHaveBeenCalledTimes(2);
    });
});
