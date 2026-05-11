import { defineConfig } from 'vitest/config';

// Root Vitest config covering every JS-touching package. Discovers
// `*.test.ts` files under any package's `tests/` directory.
//
// Run `pnpm test` (or `vitest run`) from the repo root to execute every
// package's TS tests at once. Use a path filter to scope to one package,
// e.g. `vitest run src/wayfinder`.
export default defineConfig({
    test: {
        include: ['src/*/tests/**/*.test.ts'],
        passWithNoTests: true,
    },
});
