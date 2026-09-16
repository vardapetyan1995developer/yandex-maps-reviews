import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },

    // Frontend unit tests. Scope is deliberate: the pure modules, where the
    // logic is worth pinning and no browser is required. Components are covered
    // through the end-to-end checks described in the README.
    test: {
        include: ['resources/js/**/*.test.js'],
        environment: 'node',
    },
});
