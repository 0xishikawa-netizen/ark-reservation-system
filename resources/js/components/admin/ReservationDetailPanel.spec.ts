import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ReservationDetailPanel from './ReservationDetailPanel.vue';

const router = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));

function body(): DOMWrapper<HTMLElement> {
    return new DOMWrapper(document.body);
}

const panelData = {
    can: { manage: true, view_customer: true },
    reservation: {
        id: 10,
        customer_id: 20,
        date: '2026-09-22',
        starts_at: '2026-09-22 10:00:00',
        ends_at: '2026-09-22 11:00:00',
        service_id: 30,
        service_name: 'パーソナル60分',
        staff_id: 40,
        staff_name: '山田',
        is_staff_requested: true,
        booth_name: null,
        status: 'confirmed',
        status_label: '予約確定',
        source_label: '管理画面',
        payment_method_label: '店頭払い',
        amount: 8000,
        notes: null,
        cancel_reason: null,
        version: 0,
        edit_url: '/admin/reservations/10/edit',
        payment: null,
        can_complete: true,
        visit_entry_url: '/admin/reservations/10/visit',
        can_cancel: true,
        can_no_show: true,
    },
    today_reservation_id: null,
    customer: {
        user_id: 20,
        member_no: 'ARK-000020',
        name: '山本 花',
        kana: 'ヤマモト ハナ',
        gender: 'female',
        phone: null,
        note: null,
        visit_count: 0,
        first_visit_at: null,
        last_visit_at: null,
        detail_url: '/admin/customers/20',
    },
    tickets: [],
    membership: null,
    upcoming: [],
    history: { items: [], total: 0, has_more: false },
};

let activeWrapper: VueWrapper | null = null;

afterEach(() => {
    activeWrapper?.unmount();
    activeWrapper = null;
    document.body.innerHTML = '';
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

async function mountPanel(canManage = true): Promise<VueWrapper> {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                ...panelData,
                can: { ...panelData.can, manage: canManage },
            }),
        }),
    );

    const wrapper = mount(ReservationDetailPanel, {
        props: {
            reservationId: 10,
            customerId: null,
            referenceDate: '2026-09-22',
        },
        attachTo: document.body,
    });
    activeWrapper = wrapper;
    await flushPromises();
    await nextTick();

    return wrapper;
}

describe('ReservationDetailPanel', () => {
    it('emits the current reservation fields from この内容で新規予約', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('button[aria-label="その他の操作"]').trigger('click');
        await nextTick();
        const rebook = body()
            .findAll('.v-list-item')
            .find((item) => item.text().includes('この内容で新規予約'));

        expect(rebook).toBeDefined();
        await rebook!.trigger('click');
        expect(wrapper.emitted('rebook')).toEqual([
            [{ customerId: 20, serviceId: 30, staffId: 40 }],
        ]);
    });

    it('shows 来店・会計 as the main action and no fact-less 来店完了 button (Task 11-27)', async () => {
        const wrapper = await mountPanel();
        const entry = wrapper.find('[data-testid="open-visit-entry"]');

        expect(entry.exists()).toBe(true);
        expect(entry.text()).toContain('来店・会計');
        expect(wrapper.text()).not.toMatch(/(^|\s)来店完了(\s|$)/);
        await entry.trigger('click');
        expect(router.post).toHaveBeenCalledWith('/admin/reservations/10/visit', {}, expect.any(Object));
    });

    it('completes without checkout only after choosing an explicit reason', async () => {
        const wrapper = await mountPanel();
        router.patch.mockClear();

        await wrapper.find('button[aria-label="その他の操作"]').trigger('click');
        await nextTick();
        const noCheckout = body().findAll('.v-list-item').find((item) => item.text().includes('会計なしで来店完了'));
        expect(noCheckout).toBeDefined();
        await noCheckout!.trigger('click');
        await nextTick();

        const dialog = body().find('.v-overlay--active .v-card');
        expect(dialog.text()).toContain('無料施術');
        expect(dialog.text()).toContain('事前決済済み');
        expect(dialog.text()).toContain('回数券・月額の利用');
        const submit = dialog.findAll('button').find((button) => button.text().includes('会計なしで完了'));
        expect(submit?.attributes('disabled')).toBeDefined();

        await dialog.find('input[value="prepaid"]').setValue(true);
        await nextTick();
        await submit!.trigger('click');
        expect(router.patch).toHaveBeenCalledWith(
            '/admin/reservations/10/complete',
            { exemption_reason: 'prepaid' },
            expect.any(Object),
        );
    });

    it('hides the current reservation rebook action without manage permission', async () => {
        const wrapper = await mountPanel(false);

        await wrapper.find('button[aria-label="その他の操作"]').trigger('click');
        await nextTick();

        expect(body().text()).not.toContain('この内容で新規予約');
    });
});
