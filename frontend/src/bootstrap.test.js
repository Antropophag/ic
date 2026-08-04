import { describe, expect, it, vi } from 'vitest'
import { installIdentityFetch } from '../dev/dev-tools'
import { bootstrapApplication } from './bootstrap'

function browserWindow(fetch) {
  const values = new Map([['ic.dev.userId', '7']])
  return {
    fetch,
    location: { href: 'http://localhost:8080/', origin: 'http://localhost:8080' },
    localStorage: { getItem: (key) => values.get(key) ?? null },
  }
}

describe('application bootstrap', () => {
  it('starts the application directly without development tools', async () => {
    const startApplication = vi.fn().mockReturnValue('mounted')

    await expect(bootstrapApplication({ startApplication })).resolves.toBe('mounted')

    expect(startApplication).toHaveBeenCalledOnce()
  })

  it('installs development identity before the first application request', async () => {
    const originalFetch = vi.fn().mockResolvedValue({ ok: true })
    const browser = browserWindow(originalFetch)

    await bootstrapApplication({
      loadDevelopmentTools: async () => installIdentityFetch(browser),
      startApplication: () => browser.fetch('/api/v1/auth/me'),
    })

    expect(originalFetch).toHaveBeenCalledOnce()
    expect(originalFetch.mock.calls[0][1].headers.get('X-Dev-User-ID')).toBe('7')
  })
})
