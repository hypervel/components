import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    plugins: [vue()],
    test: {
        name: 'horizon',
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
    },
    build: {
        assetsDir: '',
        rolldownOptions: {
            input: ['resources/js/app.js', 'resources/sass/styles.scss', 'resources/sass/styles-dark.scss'],
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
