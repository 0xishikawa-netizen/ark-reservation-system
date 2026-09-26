import { describe, expect, it } from 'vitest';
import { allocateByWeight, splitInclusive } from './amounts';

describe('splitInclusive', () => {
    it('floors tax from tax-inclusive gross', () => {
        expect(splitInclusive(11000, 1000)).toEqual({ net: 10000, tax: 1000 });
        expect(splitInclusive(99, 800)).toEqual({ net: 92, tax: 7 });
        expect(splitInclusive(0, 1000)).toEqual({ net: 0, tax: 0 });
    });

    it('returns null without a tax rate', () => {
        expect(splitInclusive(1000, null)).toBeNull();
    });
});

describe('allocateByWeight', () => {
    it('keeps the total and splits by minutes', () => {
        expect(allocateByWeight(10000, [30, 30])).toEqual([5000, 5000]);
        expect(allocateByWeight(10000, [45, 15])).toEqual([7500, 2500]);
        const odd = allocateByWeight(10001, [1, 1, 1]);
        expect(odd.reduce((a, b) => a + b, 0)).toBe(10001);
        expect(odd).toEqual([3334, 3334, 3333]);
    });

    it('returns zeros when there is no weight', () => {
        expect(allocateByWeight(1000, [0, 0])).toEqual([0, 0]);
    });
});
