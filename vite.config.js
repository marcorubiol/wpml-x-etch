import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    lib: {
      entry: resolve(__dirname, 'src/js/entry.js'),
      formats: ['iife'],
      name: 'WxePanel',
      fileName: () => 'wxe-panel.js',
    },
    outDir: 'assets',
    emptyOutDir: false,
    sourcemap: false,
    minify: 'esbuild',
    rollupOptions: {
      output: {
        entryFileNames: 'wxe-panel.js',
      },
    },
  },
});
