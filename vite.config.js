import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import { cpSync, existsSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

// Fontes do Excalidraw servidas pelo próprio app (window.EXCALIDRAW_ASSET_PATH = /build/excalidraw/),
// sem depender de CDN. A Xiaolai (chinês, ~12 MB) fica de fora: se aparecer, vem da CDN padrão.
const excalidrawFonts = () => {
    const copy = () => {
        const from = resolve('node_modules/@excalidraw/excalidraw/dist/prod/fonts');
        const to = resolve('public/build/excalidraw/fonts');
        if (!existsSync(from)) return;
        mkdirSync(to, { recursive: true });
        cpSync(from, to, { recursive: true, filter: (path) => !path.includes('Xiaolai') });
    };

    return {
        name: 'ward-excalidraw-fonts',
        configureServer: copy,
        closeBundle: copy,
    };
};

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/design-canvas.js', 'resources/js/design-editor.js', 'resources/js/whiteboard/main.jsx'],
            refresh: [`resources/views/**/*`],
        }),
        tailwindcss(),
        excalidrawFonts(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    define: {
        // O Excalidraw lê esta variável (build para Preact); aqui é React.
        'process.env.IS_PREACT': JSON.stringify('false'),
    },
    server: {
        cors: true,
    },
});
