import { defineConfig } from 'vite';
export default defineConfig({
	build: {
		outDir: '../plugin/assets',
		emptyOutDir: false,
		target: 'es2015',
		sourcemap: false,
		minify: true,
		lib: {
			entry: 'src/dedupe.js',
			name: 'RafooDedupe',
			formats: ['iife'],
			fileName: () => 'dedupe.js'
		}
	}
});
