import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Schedule from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { get: vi.fn(), visit: vi.fn(), put: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => undefined) },
    usePage: () => ({
        url: '/admin/schedule',
        props: {
            auth: { can: { reservationsManage: true, reservationsView: true, shiftsManage: true } },
            flash: {},
        },
    }),
}));

const staff = (id: number, name: string, isWorking = true) => ({
    user_id: id, display_name: name, color: '#1A2653', sort_order: id, is_working: isWorking,
});

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- テスト用にページpropsの一部だけを差し替える
function props(overrides: Record<string, unknown> = {}): any {
    return {
        staff: [staff(1, '出勤A'), staff(3, '出勤C')],
        off_staff: [{ user_id: 2, display_name: '休みB' }],
        staff_options: [], menu_options: [], booth_options: [], popular_service_ids: [],
        shifts: [
            { staff_id: 1, work_date: '2026-10-01', start_at: '10:00:00', end_at: '18:00:00' },
            { staff_id: 3, work_date: '2026-10-01', start_at: '10:00:00', end_at: '18:00:00' },
        ],
        reservations: [], blocks: [],
        business_hours: { open: '10:00', close: '21:00', slot_minutes: 15 },
        view: 'day' as const, axis: 'staff' as const, range: { start: '2026-10-01', end: '2026-10-01' }, days: ['2026-10-01'],
        booths: [], summary: null,
        focus_reservation_id: null, focus_customer_id: null, focus_block_id: null, panel_mode: null as null,
        create_prefill: { customer_id: null, service_id: null, staff_id: null, booth_id: null, date: null, time: null },
        block_create_prefill: { staff_id: null, booth_id: null, date: null, time: null },
        filters: { date: '2026-10-01', staff_id: null, view: 'day' as const, axis: 'staff' as const },
        ...overrides,
    };
}

const stubs = {
    ReservationDetailPanel: true, CustomerSearchPanel: true, PanelCustomerSearchBar: true, NewReservationPanel: true,
    ScheduleBlockCreatePanel: true, ScheduleBlockDetailPanel: true, SlotChoicePanel: true, ScheduleNotifications: true,
};

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; });

describe('ブッキングボードのスタッフ行', () => {
    it('出勤予定のスタッフだけを行に出し、休みの人数を示す', () => {
        wrapper = mount(Schedule, { props: props(), global: { stubs } });
        const labels = wrapper.findAll('.timeline-lane-label').map((label) => label.text());
        expect(labels).toEqual(['出勤A', '出勤C']);
        expect(wrapper.get('[data-testid="off-staff-count"]').text()).toContain('休み 1名');
        expect(wrapper.find('[data-testid="lane-off-duty"]').exists()).toBe(false);
    });

    it('休みなのに予約があるスタッフは行を残して「勤務予定外」と示す', () => {
        wrapper = mount(Schedule, {
            props: props({ staff: [staff(1, '出勤A'), staff(2, '休みB', false)], off_staff: [] }),
            global: { stubs },
        });
        expect(wrapper.findAll('.timeline-lane-label').map((label) => label.text().replace('勤務予定外', '').trim())).toEqual(['出勤A', '休みB']);
        expect(wrapper.findAll('[data-testid="lane-off-duty"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="off-staff-count"]').exists()).toBe(false);
    });

    it('出勤者がいない日は休業・勤務枠の確認を促す', () => {
        wrapper = mount(Schedule, { props: props({ staff: [], shifts: [] }), global: { stubs } });
        expect(wrapper.text()).toContain('この期間に出勤予定のスタッフはいません');
    });
});
