import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // No `fonts:` entry on purpose. The skeleton's bunny('Instrument
            // Sans') helper downloads the face from fonts.bunny.net during
            // `npm run build`, so a blocked or offline network fails the whole
            // container bootstrap. resources/css/app.css falls back to the
            // system sans stack. To restore it, re-add:
            //     import { bunny } from 'laravel-vite-plugin/fonts';
            //     fonts: [bunny('Instrument Sans', { weights: [400, 500, 600] })],
        }),
        tailwindcss(),
    ],
    server: {
        // The dev server runs inside the `vite` container, so it has to listen
        // on every interface for the published port to reach it. The browser
        // still talks to it as localhost, which is what hmr.host announces.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            // File events do not cross the macOS/Windows bind mount, so the
            // watcher has to poll to notice an edit made on the host.
            usePolling: true,
            interval: 300,
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
