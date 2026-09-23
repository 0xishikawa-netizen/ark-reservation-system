import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * フロントエンド自動テスト基盤（§13-16）。Vite本体の vite.config.ts とは別ファイルにして、
 * Laravel向けプラグイン（HMR・Vuetify自動importなど）をテスト実行に巻き込まない。
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.spec.ts'],
        css: false,
        setupFiles: ['resources/js/test-setup.ts'],
        server: {
            // Vuetify は各コンポーネントが自身の .css を import する。Vite の変換を通さず
            // 素の Node ESM ローダーで読み込むと ".css を解決できない" エラーになるため、
            // vuetify をここで Vite の変換パイプラインに乗せる（css:false と併用して no-op 化）。
            deps: {
                inline: ['vuetify'],
            },
        },
    },
});
