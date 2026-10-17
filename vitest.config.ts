import { defineConfig } from 'vitest/config';

// Each JavaScript test project is listed here, either inline or through
// its package's vitest.config.ts and any package-specific setup.
//
// Run `pnpm test` from the repo root to execute every listed package's
// tests. Scope to one package by passing it as a project name, e.g.
// `pnpm test --project wayfinder`.
export default defineConfig({
    test: {
        projects: [
            'src/wayfinder',
            {
                test: {
                    name: 'mcp',
                    include: ['tests/Mcp/**/*.test.js'],
                    environment: 'node',
                },
            },
        ],
    },
});
