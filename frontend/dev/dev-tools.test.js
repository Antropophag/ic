import { describe, expect, it, vi } from 'vitest'
import {
  installIdentityFetch,
  loadUsers,
  renderUserSwitcher,
  selectedUserId,
  startDevelopmentTools,
} from './dev-tools'
import { createDevToolsBrowserEnvironment } from '../test/browserEnvironment'

function browserEnvironment(fetch = vi.fn(), readyState = 'complete') {
  const reload = vi.fn()
  return {
    ...createDevToolsBrowserEnvironment({ fetch, readyState, reload }),
    reload,
  }
}

describe('standalone development tools', () => {
  it('loads users only from the development endpoint', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ items: [{ id: 1, displayName: 'Manager', position: 'IC', roles: ['ic_manager'] }] }),
    })
    await expect(loadUsers(fetch)).resolves.toEqual({
      items: [{ id: 1, displayName: 'Manager', position: 'IC', roles: ['ic_manager'] }],
    })
    expect(fetch).toHaveBeenCalledWith('/api/v1/dev/users')
  })

  it('reports an unavailable development endpoint', async () => {
    await expect(loadUsers(vi.fn().mockResolvedValue({ ok: false, status: 404 })))
      .rejects.toThrow('Development users are unavailable (404)')
  })

  it('persists a valid selection and adds it only to same-origin API requests', async () => {
    const originalFetch = vi.fn().mockResolvedValue({ ok: true })
    const { browserWindow: browser } = browserEnvironment(originalFetch)
    browser.localStorage.setItem('ic.dev.userId', '7')
    installIdentityFetch(browser)

    await browser.fetch('/api/v1/requests')
    const headers = originalFetch.mock.calls[0][1].headers
    expect(headers.get('X-Dev-User-ID')).toBe('7')

    await browser.fetch('http://localhost:8080/api/v1/auth/me')
    expect(originalFetch.mock.calls[1][1].headers.get('X-Dev-User-ID')).toBe('7')

    await browser.fetch(new URL('/api/v1/auth/me', browser.location.href))
    expect(originalFetch.mock.calls[2][1].headers.get('X-Dev-User-ID')).toBe('7')

    await browser.fetch('https://example.invalid/api/v1/requests')
    expect(originalFetch.mock.calls[3][1].headers.get('X-Dev-User-ID')).toBeNull()

    await browser.fetch(new URL('/api/v1/requests', 'https://example.invalid'))
    expect(originalFetch.mock.calls[4][1].headers.get('X-Dev-User-ID')).toBeNull()
    expect(selectedUserId(browser)).toBe('7')
  })

  it('does not add an identity header without a selected user', async () => {
    const originalFetch = vi.fn().mockResolvedValue({ ok: true })
    const { browserWindow: browser } = browserEnvironment(originalFetch)
    installIdentityFetch(browser)

    await browser.fetch('/api/v1/auth/me')

    expect(originalFetch.mock.calls[0][1].headers.get('X-Dev-User-ID')).toBeNull()
  })

  it('supports string inputs when Request is unavailable', async () => {
    vi.stubGlobal('Request', undefined)
    const originalFetch = vi.fn().mockResolvedValue({ ok: true })
    const { browserWindow: browser } = browserEnvironment(originalFetch)
    browser.localStorage.setItem('ic.dev.userId', '7')

    try {
      installIdentityFetch(browser)
      await browser.fetch('/api/v1/auth/me')
    } finally {
      vi.unstubAllGlobals()
    }

    expect(originalFetch.mock.calls[0][1].headers.get('X-Dev-User-ID')).toBe('7')
  })

  it('renders only safe display fields and switches identity after reload', () => {
    const { browserWindow: browser, document, reload } = browserEnvironment()
    browser.localStorage.setItem('ic.dev.userId', '1')
    renderUserSwitcher(browser, document, [{
      id: 1,
      displayName: 'Manager',
      position: 'IC',
      email: 'must-not-render@example.invalid',
      roles: ['employee', 'administrator'],
    }, {
      id: 2,
      displayName: 'Executor',
      position: 'Lab',
      roles: ['employee', 'ic_executor'],
    }])

    const select = document.body.children[0].children[0].children[1]
    expect(select.children.map((option) => option.textContent)).toEqual([
      'Manager — IC',
      'Executor — Lab',
    ])
    expect(JSON.stringify(document.body)).not.toContain('must-not-render@example.invalid')

    select.value = '2'
    select.dispatchEvent(new Event('change'))
    expect(selectedUserId(browser)).toBe('2')
    expect(reload).toHaveBeenCalledOnce()
  })

  it('offers demo data only to the selected administrator', () => {
    const { browserWindow: browser, document } = browserEnvironment()
    browser.localStorage.setItem('ic.dev.userId', '1')
    renderUserSwitcher(browser, document, [
      { id: 1, displayName: 'Admin', position: 'Administrator', roles: ['administrator'] },
      { id: 2, displayName: 'Employee', position: 'Engineer', roles: ['employee'] },
    ])

    expect(document.body.children[0].children[1].textContent).toBe('Заполнить демо')

    const second = browserEnvironment()
    second.browserWindow.localStorage.setItem('ic.dev.userId', '2')
    renderUserSwitcher(second.browserWindow, second.document, [
      { id: 1, displayName: 'Admin', position: 'Administrator', roles: ['administrator'] },
      { id: 2, displayName: 'Employee', position: 'Engineer', roles: ['employee'] },
    ])
    expect(second.document.body.children[0].children).toHaveLength(1)
  })

  it('replaces an invalid persisted selection with the first available user', () => {
    const { browserWindow: browser, document, reload } = browserEnvironment()
    browser.localStorage.setItem('ic.dev.userId', '999')
    renderUserSwitcher(browser, document, [
      { id: 1, displayName: 'Manager', position: 'IC', roles: ['ic_manager'] },
    ])

    expect(selectedUserId(browser)).toBe('1')
    expect(reload).toHaveBeenCalledOnce()
    expect(document.body.children).toEqual([])
  })

  it('does not render a switcher for an empty user list', () => {
    const { browserWindow: browser, document, reload } = browserEnvironment()
    renderUserSwitcher(browser, document, [])

    expect(document.body.children).toEqual([])
    expect(reload).not.toHaveBeenCalled()
  })

  it('renders the switcher after DOMContentLoaded when the document is loading', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ items: [{ id: 1, displayName: 'Manager', position: 'IC', roles: ['ic_manager'] }] }),
    })
    const { browserWindow: browser, document, dispatchDOMContentLoaded } = browserEnvironment(fetch, 'loading')
    browser.localStorage.setItem('ic.dev.userId', '1')

    startDevelopmentTools(browser, document)

    expect(fetch).not.toHaveBeenCalled()
    dispatchDOMContentLoaded()
    await vi.waitFor(() => expect(document.body.children).toHaveLength(1))
    dispatchDOMContentLoaded()
    expect(fetch).toHaveBeenCalledOnce()
  })

  it.each(['interactive', 'complete'])('renders the switcher immediately when readyState is %s', async (readyState) => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ items: [{ id: 1, displayName: 'Manager', position: 'IC', roles: ['ic_manager'] }] }),
    })
    const { browserWindow: browser, document } = browserEnvironment(fetch, readyState)
    browser.localStorage.setItem('ic.dev.userId', '1')

    startDevelopmentTools(browser, document)

    await vi.waitFor(() => expect(document.body.children).toHaveLength(1))
    expect(fetch).toHaveBeenCalledOnce()
  })

  it('reports an unavailable endpoint after DOMContentLoaded', async () => {
    const { browserWindow: browser, document, dispatchDOMContentLoaded } = browserEnvironment(
      vi.fn().mockResolvedValue({ ok: false, status: 503 }),
      'loading',
    )
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    startDevelopmentTools(browser, document)

    dispatchDOMContentLoaded()
    await vi.waitFor(() => expect(error).toHaveBeenCalledWith(
      'Development tools failed:',
      expect.objectContaining({ message: 'Development users are unavailable (503)' }),
    ))
    error.mockRestore()
  })
})
