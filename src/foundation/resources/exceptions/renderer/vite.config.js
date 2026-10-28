import { defineConfig } from 'vite-plus';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        rolldownOptions: {
            input: ['styles.css', 'scripts.js'],
            output: {
                assetFileNames: '[name][extname]',
                entryFileNames: '[name].js',
            },
        },
    },
});
