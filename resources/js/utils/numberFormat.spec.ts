import { describe, expect, it } from 'vitest';
import { signed } from './numberFormat';

describe('signed', () => {
    it('正の値だけ + を付ける', () => {
        expect(signed(3)).toBe('+3');
        expect(signed(0)).toBe('0');
        expect(signed(-2)).toBe('-2');
    });
});
