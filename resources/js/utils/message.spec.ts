import { describe, expect, it } from 'vitest';
import { fillMessage } from './message';

describe('fillMessage', () => {
    it('{名前} を値で置き換える', () => {
        expect(fillMessage('{name}様（{count}件）', { name: '山田', count: 3 })).toBe('山田様（3件）');
    });

    it('値に $ の並びがあってもそのまま差し込む（String.replace の特殊置換を起こさない）', () => {
        expect(fillMessage('{name}を削除しますか？', { name: "A$&B$1$'" })).toBe("A$&B$1$'を削除しますか？");
    });

    it('同じ名前が複数あればすべて置き換える', () => {
        expect(fillMessage('{x}-{x}', { x: 1 })).toBe('1-1');
    });

    it('null / undefined は空文字、渡していない名前はそのまま残す', () => {
        expect(fillMessage('[{a}][{b}][{c}]', { a: null, b: undefined })).toBe('[][][{c}]');
    });

    it('0 は空にしない', () => {
        expect(fillMessage('{count}件', { count: 0 })).toBe('0件');
    });
});
