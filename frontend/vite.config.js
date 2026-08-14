import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    coverage: {
      provider: 'v8',
      include: ['src/api.js', 'src/applicationDraftForm.js', 'src/applicationDraftStorage.js', 'src/bootstrap.js', 'src/confirmDialog.js', 'src/latestRequestGuard.js', 'src/registry.js', 'src/requestRegistryLoadLifecycle.js', 'src/components/RequestDetails.vue', 'src/components/RequestActions.vue', 'src/components/RequestActivity.vue', 'src/components/RequestDocuments.vue', 'src/composables/useRequestActions.js', 'dev/dev-tools.js', 'dev/review-guide.js'],
      reporter: ['text', 'json-summary', 'lcov'],
      thresholds: {
        lines: 80,
        functions: 80,
        // Vue template compilation adds synthetic statements and branches;
        // line/function thresholds remain the stable behavioral signal.
        statements: 75,
        branches: 60,
      },
    },
  },
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
      '/health': 'http://localhost:8080',
    },
  },
})
