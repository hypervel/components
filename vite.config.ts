import { defineConfig } from 'vite-plus';

const ignoredFiles = [
    '**/node_modules/**',
    '**/vendor/**',
    '**/dist/**',
    '**/public/build/**',
    '**/*.min.js',
    '**/*.blade.*',
    '_archive/**',
    'skeletons/**',
    'tests/**/Fixtures/**',
    'src/wayfinder/tests/.generated/**',
    'src/telescope/resources/js/screens/dumps/sfdump.js',
];

export default defineConfig({
    fmt: {
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        printWidth: 120,
        ignorePatterns: [...ignoredFiles, '**/*.php', '**/*.md', '**/*.json', '**/*.yaml', '**/*.yml'],
        overrides: [
            { files: ['src/horizon/**/*.vue'], options: { vueIndentScriptAndStyle: true } },
            { files: ['src/telescope/**/*.vue'], options: { vueIndentScriptAndStyle: false } },
            { files: ['src/mcp/**', 'tests/Mcp/**'], options: { singleQuote: false } },
            {
                files: ['src/wayfinder/**'],
                options: { singleQuote: false, trailingComma: 'all', printWidth: 80 },
            },
        ],
    },
    lint: {
        options: { typeAware: true, typeCheck: true },
        ignorePatterns: ignoredFiles,
        env: { browser: true },
        rules: {
            'typescript/no-floating-promises': [
                'warn',
                {
                    // The Node test runner owns completion and failure reporting.
                    allowForKnownSafeCalls: [{ from: 'package', name: 'test', package: 'node:test' }],
                },
            ],
        },
        overrides: [
            {
                files: ['**/*.config.*', '**/*.test.*', 'src/wayfinder/tests/**', '.github/scripts/**'],
                env: { node: true },
            },
            {
                files: ['**/*.test.*'],
                // Mock assertions inspect methods without calling them.
                rules: { 'typescript/unbound-method': 'off' },
            },
            { files: ['src/horizon/resources/**'], globals: { Horizon: 'readonly' } },
            { files: ['src/telescope/resources/**'], globals: { Telescope: 'readonly' } },
        ],
    },
    test: {
        projects: [
            'src/wayfinder',
            'src/horizon',
            'src/telescope',
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
