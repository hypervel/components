import { defineConfig } from 'vitest/config';

// Root Vitest config. Each subpackage with TS tests is listed explicitly
// in `projects` and brings its own vitest.config.ts (and any globalSetup,
// environment, etc.). Adding a new package requires adding it here too.
//
// Run `pnpm test` from the repo root to execute every listed package's
// tests. Scope to one package by passing it as a project name, e.g.
// `pnpm test --project wayfinder`.
export default defineConfig({
    test: {
        projects: [
            'src/wayfinder',
        ],
    },
});
