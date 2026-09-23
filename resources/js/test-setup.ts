/**
 * Vitest 用の共通セットアップ。Vuetify コンポーネントを jsdom 上でマウントするために
 * 最低限必要なブラウザAPIのポリフィルだけを用意する（§13）。
 */
import { config } from '@vue/test-utils';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';

if (typeof window.matchMedia !== 'function') {
    window.matchMedia = (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => undefined,
        removeListener: () => undefined,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        dispatchEvent: () => false,
    }) as unknown as MediaQueryList;
}

if (typeof window.ResizeObserver !== 'function') {
    window.ResizeObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}

if (typeof window.visualViewport === 'undefined') {
    // Vuetify の VOverlay 位置計算が参照する。jsdom には存在しないため最小スタブを与える。
    Object.defineProperty(window, 'visualViewport', {
        writable: true,
        value: {
            width: 1024,
            height: 768,
            offsetLeft: 0,
            offsetTop: 0,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
        },
    });
}

if (typeof window.IntersectionObserver !== 'function') {
    // @ts-expect-error jsdom用の最小スタブでよい
    window.IntersectionObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}

const vuetify = createVuetify({ components, directives });

config.global.plugins = [vuetify];
