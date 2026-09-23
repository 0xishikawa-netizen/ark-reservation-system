import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WeeklyAvailabilityTimetable from './WeeklyAvailabilityTimetable.vue';

const weekResponse = {
    days: Array.from({ length: 7 }, (_, index) => {
        const date = new Date(2026, 8, 22 + index);
        const weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return {
            date: `2026-09-${String(22 + index).padStart(2, '0')}`,
            label: `9/${22 + index}(${weekdays[date.getDay()]})`,
        };
    }),
    times: ['10:00', '10:30'],
    cells: Object.fromEntries(Array.from({ length: 7 }, (_, index) => [
        `2026-09-${String(22 + index).padStart(2, '0')}`,
        { '10:00': index === 0 ? 'open' : 'full', '10:30': index === 0 ? 'some' : 'full' },
    ])),
};

let wrapper: VueWrapper | null = null;

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 8, 22, 9, 0, 0));
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

async function mountTimetable(fetchImplementation?: typeof fetch): Promise<VueWrapper> {
    const fetchMock = fetchImplementation ?? vi.fn(async () => ({
        ok: true,
        json: async () => weekResponse,
    })) as unknown as typeof fetch;
    vi.stubGlobal('fetch', fetchMock);
    wrapper = mount(WeeklyAvailabilityTimetable, {
        props: {
            modelValue: null,
            serviceId: 10,
            staffId: null,
            weekEndpoint: '/booking/availability/week',
        },
        attachTo: document.body,
    });
    await vi.advanceTimersByTimeAsync(150);
    await flushPromises();

    return wrapper;
}

describe('WeeklyAvailabilityTimetable', () => {
    it('renders seven day columns and the 30-minute status cells', async () => {
        const mounted = await mountTimetable();

        expect(mounted.findAll('thead th')).toHaveLength(8);
        expect(mounted.findAll('tbody tr')).toHaveLength(2);
        expect(mounted.text()).toContain('9/22(火) 〜 9/28(月)');
        expect(mounted.find('[aria-label="9/22(火) 10:00 空きあり"]').text()).toBe('○');
        expect(mounted.find('[aria-label="9/22(火) 10:30 残りわずか"]').text()).toBe('△');
    });

    it('selects open and some cells but keeps full cells disabled', async () => {
        const mounted = await mountTimetable();
        const openCell = mounted.find('[aria-label="9/22(火) 10:00 空きあり"]');
        const fullCell = mounted.find('[aria-label="9/23(水) 10:00 空きなし"]');

        await openCell.trigger('click');

        expect(mounted.emitted('update:modelValue')).toContainEqual(['2026-09-22 10:00:00']);
        expect(fullCell.attributes('disabled')).toBeDefined();
    });

    it('starts at today, disables the previous week, and fetches the next seven-day window', async () => {
        const fetchMock = vi.fn(async () => ({ ok: true, json: async () => weekResponse })) as unknown as typeof fetch;
        const mounted = await mountTimetable(fetchMock);
        const buttons = mounted.findAll('button');
        const previous = buttons.find((button) => button.text().includes('前の週'))!;
        const next = buttons.find((button) => button.text().includes('次の週'))!;

        expect(previous.attributes('disabled')).toBeDefined();
        await next.trigger('click');
        await vi.advanceTimersByTimeAsync(150);
        await flushPromises();

        expect(fetchMock).toHaveBeenLastCalledWith(
            expect.stringContaining('start_date=2026-09-29'),
            expect.any(Object),
        );
    });

    it('debounces a staff change, clears selection, and refetches with staff_id', async () => {
        const fetchMock = vi.fn(async () => ({ ok: true, json: async () => weekResponse })) as unknown as typeof fetch;
        const mounted = await mountTimetable(fetchMock);

        await mounted.setProps({ staffId: 42, modelValue: '2026-09-22 10:00:00' });
        await vi.advanceTimersByTimeAsync(149);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        await flushPromises();

        expect(mounted.emitted('update:modelValue')?.at(-1)).toEqual([null]);
        expect(fetchMock).toHaveBeenLastCalledWith(
            expect.stringContaining('staff_id=42'),
            expect.any(Object),
        );
    });
});
