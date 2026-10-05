import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Report the legacy workflow component to Sonar's changed-code coverage gate.
// The existing global 80% gate still runs first with vite.config.js.
export default defineConfig({
  plugins: [vue()],
  test: {
    include: ['src/components/RequestDetails.test.js'],
    coverage: {
      provider: 'v8',
      include: ['src/components/RequestDetails.vue'],
      reportsDirectory: 'coverage/workflow',
      reporter: ['text', 'json-summary', 'lcov'],
    },
  },
})
