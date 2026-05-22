import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
                'resources/css/filament/portal/theme.css',
                'resources/css/filament/supplier-portal/theme.css',
                'resources/css/filament/forwarder-portal/theme.css',
                'resources/css/filament/fair/theme.css',
                'resources/css/fair-mobile/app.css',
                'resources/js/fair-mobile/app.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
