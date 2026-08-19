import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

// publicDir is enabled for `vite dev` only: the SCSS references fonts by an
// absolute /fonts/... URL, which the browser resolves against the dev-server
// origin, not the Laravel one. In a build Vite would copy public/ into the
// bundle, so it stays off there.
export default defineConfig(({ command }) => ({
    publicDir: command === 'serve' ? 'public' : false,
    plugins: [
        laravel({
            input: [
                'resources/scss/app.scss',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: [
                'resources/views/**',
                'resources/css/**',
                'resources/scss/**',
                'resources/js/**',
                'routes/**',
            ],
            fonts: [
                bunny('Golos Text', { weights: [400, 500, 600] }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: Number(process.env.VITE_PORT ?? 5173),
        strictPort: true,
        hmr: {
            host: process.env.VITE_HMR_HOST ?? 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
