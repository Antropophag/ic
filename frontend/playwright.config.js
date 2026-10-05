import { defineConfig, devices } from '@playwright/test'

const guideTests = ['**/review-guide.e2e.js']
const statefulTests = ['**/idempotency.e2e.js', '**/notifications.e2e.js']

export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.e2e.js',
  fullyParallel: false,
  retries: 0,
  reporter: process.env.CI ? [['line'], ['html', { open: 'never' }]] : 'line',
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 5175 --strictPort',
    url: 'http://127.0.0.1:5175',
    reuseExistingServer: false,
  },
  projects: [
    {
      name: 'review-guide',
      testMatch: guideTests,
      use: { ...devices['Desktop Chrome'], baseURL: 'http://127.0.0.1:5175' },
    },
    {
      name: 'chromium',
      testIgnore: [...statefulTests, ...guideTests],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'stateful-chromium',
      testMatch: statefulTests,
      dependencies: ['chromium'],
      workers: 1,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
