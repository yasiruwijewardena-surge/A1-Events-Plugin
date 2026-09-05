import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Build output is written straight into the WordPress plugin's assets
// folder so the plugin can enqueue it via manifest.json (hashed filenames).
// See wordpress-plugin/events-showcase/includes/class-assets.php.
export default defineConfig({
  plugins: [react()],
  base: '',
  build: {
    outDir: '../wordpress-plugin/events-showcase/assets/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: 'src/main.jsx',
    },
  },
});
