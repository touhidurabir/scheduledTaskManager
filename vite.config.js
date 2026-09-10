/**
 * @file vite.config.js
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 */

import {resolve} from 'path';
import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import i18nExtractKeys from './i18nExtractKeys.vite.js';

// https://vitejs.dev/config/
export default defineConfig({
	target: 'es2016',
	// Nothing to copy verbatim; also stops Vite warning about publicDir overlapping outDir.
	publicDir: false,
	plugins: [i18nExtractKeys(), vue()],
	build: {
		lib: {
			entry: resolve(__dirname, 'resources/js/main.js'),
			name: 'ScheduledTaskManager',
			fileName: 'build',
			// An immediately invoked function expression can be loaded with a plain script tag
			// and runs as soon as it is parsed, so the components are registered before
			// backend.tpl calls pkp.registry.init().
			formats: ['iife'],
		},
		outDir: resolve(__dirname, 'public/build'),
		rollupOptions: {
			external: ['vue'],
			output: {
				globals: {
					vue: 'pkp.modules.vue',
				},
			},
		},
	},
});
