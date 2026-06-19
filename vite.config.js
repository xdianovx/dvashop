import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js'],
            // Hot reload: changes to these paths trigger a full page reload in dev.
            // CSS edits hot-swap via Vite HMR without a reload.
            refresh: [
                'resources/views/**',
                'resources/css/**',
                'resources/js/**',
                'routes/**',
            ],
            // Gilroy + Montserrat are self-hosted (public/fonts, see resources/css/fonts.css).
            // Only Golos Text comes from Bunny Fonts.
            fonts: [
                bunny('Golos Text', { weights: [400, 500, 600] }),
            ],
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
