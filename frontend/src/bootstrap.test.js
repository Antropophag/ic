import { describe, expect, it, vi } from 'vitest'
import { bootstrapApplication, developmentToolsLoader } from './bootstrap'
import { createDevToolsBrowserEnvironment } from '../test/browserEnvironment'

describe('application bootstrap', () => {
  it('starts the application directly without development tools', async () => {
    const startApplication = vi.fn().mockReturnValue('mounted')

    await expect(bootstrapApplication({ startApplication })).resolves.toBe('mounted')

    expect(startApplication).toHaveBeenCalledOnce()
  })

  it('installs development identity before the first application request', async () => {
    const originalFetch = vi.fn().mockResolvedValue({ ok: true })
    const { browserWindow: browser, document } = createDevToolsBrowserEnvironment({
      fetch: originalFetch,
      readyState: 'loading',
    })
    browser.localStorage.setItem('ic.dev.userId', '7')

    await bootstrapApplication({
      loadDevelopmentTools: developmentToolsLoader(
        browser,
        document,
        () => import('../dev/dev-tools'),
      ),
      startApplication: () => browser.fetch('/api/v1/auth/me'),
    })

    expect(originalFetch).toHaveBeenCalledOnce()
    expect(originalFetch.mock.calls[0][1].headers.get('X-Dev-User-ID')).toBe('7')
  })
})
