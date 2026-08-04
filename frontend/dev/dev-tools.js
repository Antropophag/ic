const storageKey = 'ic.dev.userId'

export function selectedUserId(browserWindow) {
  const value = browserWindow.localStorage.getItem(storageKey)
  return value && /^\d+$/.test(value) ? value : null
}

export function installIdentityFetch(browserWindow) {
  const originalFetch = browserWindow.fetch.bind(browserWindow)
  const RequestCtor = browserWindow.Request ?? globalThis.Request
  browserWindow.fetch = (input, init = {}) => {
    const isRequest = Boolean(RequestCtor) && input instanceof RequestCtor
    const url = isRequest ? input.url : input
    const parsedUrl = new URL(url, browserWindow.location.href)
    const sameOriginApi = parsedUrl.origin === browserWindow.location.origin && parsedUrl.pathname.startsWith('/api/')
    const headers = new Headers(init.headers || (isRequest ? input.headers : undefined))
    const userId = selectedUserId(browserWindow)
    if (sameOriginApi && userId) {
      headers.set('X-Dev-User-ID', userId)
    }
    return originalFetch(input, {...init, headers})
  }
  return originalFetch
}

export async function loadUsers(fetchImpl) {
  const response = await fetchImpl('/api/v1/dev/users')
  if (!response.ok) throw new Error(`Development users are unavailable (${response.status})`)
  return response.json()
}

export function renderUserSwitcher(browserWindow, document, users) {
  if (!Array.isArray(users) || users.length === 0) return
  let current = selectedUserId(browserWindow)
  if (!current || !users.some((user) => String(user.id) === current)) {
    current = String(users[0].id)
    browserWindow.localStorage.setItem(storageKey, current)
    browserWindow.location.reload()
    return
  }

  const panel = document.createElement('aside')
  panel.setAttribute('aria-label', 'Development tools')
  panel.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;padding:10px 12px;border:1px solid #d8b34b;border-radius:10px;background:#fff8dc;box-shadow:0 4px 18px #0002;font:13px sans-serif'
  const label = document.createElement('label')
  label.textContent = 'Пользователь: '
  const select = document.createElement('select')
  select.setAttribute('aria-label', 'Пользователь разработки')
  for (const user of users) {
    const option = document.createElement('option')
    option.value = String(user.id)
    const roles = Array.isArray(user.roles) ? user.roles.join(', ') : ''
    option.textContent = `${user.displayName} — ${user.position}${roles ? ` [${roles}]` : ''}`
    option.selected = option.value === current
    select.append(option)
  }
  select.addEventListener('change', () => {
    browserWindow.localStorage.setItem(storageKey, select.value)
    browserWindow.location.reload()
  })
  label.append(select)
  panel.append(label)
  document.body.append(panel)
}

export function startDevelopmentTools(browserWindow, document) {
  const originalFetch = installIdentityFetch(browserWindow)
  const loadSwitcher = () => {
    loadUsers(originalFetch)
      .then((result) => renderUserSwitcher(browserWindow, document, result.items))
      .catch((error) => console.error('Development tools failed:', error))
  }
  if (document.readyState === 'loading') {
    browserWindow.addEventListener('DOMContentLoaded', loadSwitcher, { once: true })
  } else {
    loadSwitcher()
  }
}
