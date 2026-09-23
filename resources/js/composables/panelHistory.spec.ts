import { describe, expect, it } from 'vitest';
import { popPanelHistoryStack, pushPanelHistoryStack } from './panelHistory';

describe('panelHistory', () => {
    it('pushes an entry onto an empty stack', () => {
        const stack = pushPanelHistoryStack<string>([], 'a', 5);

        expect(stack).toEqual(['a']);
    });

    it('appends to the end (most recent state is last, for LIFO pop)', () => {
        const stack = pushPanelHistoryStack(pushPanelHistoryStack<string>([], 'a', 5), 'b', 5);

        expect(stack).toEqual(['a', 'b']);
    });

    it('caps the stack at the given limit, dropping the oldest entries', () => {
        let stack: number[] = [];

        for (let i = 1; i <= 7; i += 1) {
            stack = pushPanelHistoryStack(stack, i, 5);
        }

        expect(stack).toEqual([3, 4, 5, 6, 7]);
    });

    it('pops the most recently pushed entry (LIFO)', () => {
        const stack = ['a', 'b', 'c'];
        const { target, rest } = popPanelHistoryStack(stack);

        expect(target).toBe('c');
        expect(rest).toEqual(['a', 'b']);
    });

    it('popping an empty stack returns null and leaves it empty', () => {
        const { target, rest } = popPanelHistoryStack<string>([]);

        expect(target).toBeNull();
        expect(rest).toEqual([]);
    });

    it('does not mutate the original stack array', () => {
        const original = ['a', 'b'];
        const { rest } = popPanelHistoryStack(original);

        expect(original).toEqual(['a', 'b']);
        expect(rest).not.toBe(original);
    });
});
