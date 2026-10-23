import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import saolaBuilder from '@saolabs/builder/vite';
import path from 'path';
import fs from 'node:fs';

const context = process.env.VITE_CONTEXT || 'web';

function saolaAssets({ context, root }) {
    const src = path.resolve(root, `resources/saola/${context}/assets`);
    const publicDest = path.resolve(root, `public/static/saola/${context}/assets`);
    let isBuild = false;

    const copyDir = (from, to) => {
        fs.mkdirSync(to, { recursive: true });
        for (const e of fs.readdirSync(from, { withFileTypes: true })) {
            const s = path.join(from, e.name);
            const d = path.join(to, e.name);
            if (e.isDirectory()) copyDir(s, d);
            else fs.copyFileSync(s, d);
        }
    };
    const sync = (dest) => {
        try {
            if (fs.existsSync(src)) copyDir(src, dest);
        } catch (e) {
            console.warn(`[saola-assets] copy ${src} → ${dest} skipped: ${e.message}`);
        }
    };

    return {
        name: 'saola-assets',
        configResolved(c) { isBuild = c.command === 'build'; },
        buildStart() { if (!isBuild) sync(publicDest); },
        writeBundle(out) { sync(path.join(out.dir, 'assets')); },
        configureServer(server) {
            server.watcher.add(src);
            const onEvt = (file) => {
                if (!file.startsWith(src + path.sep)) return;
                sync(publicDest);
                server.ws.send({ type: 'full-reload' });
            };
            server.watcher.on('add', onEvt).on('change', onEvt).on('unlink', onEvt);
        },
    };
}

const devHost = process.env.VITE_DEV_HOST || '127.0.0.1';
const hmrHost = process.env.VITE_HMR_HOST || '127.0.0.1';
const devPort = Number(process.env.VITE_DEV_PORT || 5174);
const hmrClientPort = Number(process.env.VITE_HMR_CLIENT_PORT || devPort);

export default defineConfig({
    base: `/static/saola/${context}/`,
    plugins: [
        saolaBuilder({ context, watch: true }),
        saolaAssets({ context, root: __dirname }),
        laravel({
            input: [
                `resources/js/saola/app.${context}.js`,
                `resources/css/app.css`,
            ],
            refresh: [
                'app/**',
                'routes/**',
                'resources/views/**',
                'lang/**',
            ],
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@sao': path.resolve(__dirname, 'resources/saola'),
            '@app': path.resolve(__dirname, 'resources/saola/_app'),
            '@web': path.resolve(__dirname, 'resources/saola/web'),
            '@admin': path.resolve(__dirname, 'resources/saola/admin'),
            '@compiled': path.resolve(__dirname, 'resources/js/saola'),
            '@views': path.resolve(__dirname, `resources/js/saola/${context}`),
        }
    },
    publicDir: false,
    build: {
        sourcemap: true,
        outDir: `public/static/saola/${context}`,
        assetsDir: 'js',
        rollupOptions: {
            preserveEntrySignatures: 'exports-only',
            output: {
                entryFileNames: (chunk) => {
                    const src = chunk.facadeModuleId;
                    const name = src
                        ? path.basename(src).replace(/\.[cm]?[jt]sx?$/, '')
                        : chunk.name;
                    return `js/${name}.js`;
                },
                chunkFileNames: 'js/[name].js',
                assetFileNames: (info) => {
                    const names = info.names ?? (info.name ? [info.name] : []);
                    if (names.some((n) => n.endsWith('.css'))) {
                        return 'css/[name][extname]';
                    }
                    return 'assets/[name]-[hash].[ext]';
                }
            }
        }
    },
    server: {
        host: devHost,
        port: devPort,
        strictPort: true,
        hmr: {
            host: hmrHost,
            port: devPort,
            clientPort: hmrClientPort,
        },
        watch: {
            ignored: ['**/storage/**'],
            usePolling: process.env.CHOKIDAR_USEPOLLING === 'true',
            interval: 250,
        },
    },
});
