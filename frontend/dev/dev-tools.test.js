import { describe, expect, it, vi } from 'vitest'
import {
  installIdentityFetch,
  loadUsers,
  renderUserSwitcher,
  selectedUserId,
  startDevelopmentTools,
} from './dev-tools'

function browserWindow(fetch = vi.fn()) {
  const values = new Map()
  return {
    fetch,
    addEventListener: vi.fn(),
    location: { href: 'http://localhost:8080/requests', origin: 'http://localhost:8080', reload: vi.fn() },
    localStorage: {
      getItem: (key) => values.get(key) ?? null,
      setItem: (key, value) => values.set(key, value),
    },
  }
}

function element(tag) {
  return {
    tag,
    children: [],
    style: {},
    listeners: {},
    setAttribute: vi.fn(),
    append(child) { this.children.push(child) },
    addEventListener(name, listener) { this.listeners[name] = listener },
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
    const browser = browserWindow(originalFetch)
    browser.localStorage.setItem('ic.dev.userId', '7')
    installIdentityFetch(browser)

    await browser.fetch('/api/v1/requests')
    const headers = originalFetch.mock.calls[0][1].headers
    expect(headers.get('X-Dev-User-ID')).toBe('7')

    await browser.fetch('http://localhost:8080/api/v1/auth/me')
    expect(originalFetch.mock.calls[1][1].headers.get('X-Dev-User-ID')).toBe('7')

    await browser.fetch('https://example.invalid/api/v1/requests')
    expect(originalFetch.mock.calls[2][1].headers.get('X-Dev-User-ID')).toBeNull()
    expect(selectedUserId(browser)).toBe('7')
  })

  it('does not add an identity header without a selected user', async () => {
    const originalFetch = vi.fn().mockResolvedValue({ ok: true })
    const browser = browserWindow(originalFetch)
    installIdentityFetch(browser)

    await browser.fetch('/api/v1/auth/me')

    expect(originalFetch.mock.calls[0][1].headers.get('X-Dev-User-ID')).toBeNull()
  })

  it('renders only safe display fields and switches identity after reload', () => {
    const browser = browserWindow()
    browser.localStorage.setItem('ic.dev.userId', '1')
    const body = element('body')
    const document = { body, createElement: (tag) => element(tag) }
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

    const select = body.children[0].children[0].children[0]
    expect(select.children.map((option) => option.textContent)).toEqual([
      'Manager — IC [employee, administrator]',
      'Executor — Lab [employee, ic_executor]',
    ])
    expect(JSON.stringify(body)).not.toContain('must-not-render@example.invalid')

    select.value = '2'
    select.listeners.change()
    expect(selectedUserId(browser)).toBe('2')
    expect(browser.location.reload).toHaveBeenCalledOnce()
  })

  it('replaces an invalid persisted selection with the first available user', () => {
    const browser = browserWindow()
    browser.localStorage.setItem('ic.dev.userId', '999')
    const body = element('body')
    renderUserSwitcher(browser, { body, createElement: (tag) => element(tag) }, [
      { id: 1, displayName: 'Manager', position: 'IC', roles: ['ic_manager'] },
    ])

    expect(selectedUserId(browser)).toBe('1')
    expect(browser.location.reload).toHaveBeenCalledOnce()
    expect(body.children).toEqual([])
  })

  it('does not render a switcher for an empty user list', () => {
    const browser = browserWindow()
    const body = element('body')
    renderUserSwitcher(browser, { body, createElement: (tag) => element(tag) }, [])

    expect(body.children).toEqual([])
    expect(browser.location.reload).not.toHaveBeenCalled()
  })

  it('starts after DOMContentLoaded and reports an unavailable endpoint', async () => {
    const browser = browserWindow(vi.fn().mockResolvedValue({ ok: false, status: 503 }))
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    startDevelopmentTools(browser, { body: element('body'), createElement: (tag) => element(tag) })

    expect(browser.addEventListener).toHaveBeenCalledWith('DOMContentLoaded', expect.any(Function))
    browser.addEventListener.mock.calls[0][1]()
    await vi.waitFor(() => expect(error).toHaveBeenCalledWith(
      'Development tools failed:',
      expect.objectContaining({ message: 'Development users are unavailable (503)' }),
    ))
    error.mockRestore()
  })
})
