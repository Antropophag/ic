import { defineConfig, devices } from '@playwright/test'

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
  projects: [
    {
      name: 'chromium',
      testIgnore: statefulTests,
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
