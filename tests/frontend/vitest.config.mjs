import { defineConfig } from 'vitest/config';

export default defineConfig({
    root: new URL('../../', import.meta.url).pathname,
    resolve: { alias: { '@app': new URL('../../resources/saola/_app', import.meta.url).pathname } },
    test: { environment: 'jsdom', include: ['tests/frontend/*.test.ts'], restoreMocks: true },
});
