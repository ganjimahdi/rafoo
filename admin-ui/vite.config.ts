import { svelte } from '@sveltejs/vite-plugin-svelte';
import { defineConfig } from 'vite';

export default defineConfig({
	plugins: [svelte()],
	build: {
		outDir: '../plugin/assets',
		emptyOutDir: false,
		target: 'es2020',
		cssCodeSplit: false,
		sourcemap: false,
		lib: {
			entry: 'src/main.ts',
			name: 'RafooAdmin',
			formats: ['iife'],
			fileName: () => 'admin.js',
			cssFileName: 'admin'
		},
		rollupOptions: {
			output: {
				assetFileNames: 'admin.[ext]'
			}
		}
	}
});
