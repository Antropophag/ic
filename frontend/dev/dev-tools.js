const storageKey = 'ic.dev.userId'
const guideNavigationStates = new WeakMap()

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

  const previousGuideNavigation = guideNavigationStates.get(browserWindow)
  previousGuideNavigation?.controller.abort()
  previousGuideNavigation?.panel.remove()

  const panel = document.createElement('aside')
  panel.className = 'development-tools'
  panel.setAttribute('aria-label', 'Инструменты разработки')
  const label = document.createElement('label')
  label.className = 'development-tools-user'
  const labelText = document.createElement('span')
  labelText.className = 'visually-hidden'
  labelText.textContent = 'Пользователь для проверки'
  const select = document.createElement('select')
  select.setAttribute('aria-label', 'Пользователь для проверки')
  for (const user of users) {
    const option = document.createElement('option')
    option.value = String(user.id)
    option.textContent = `${user.displayName} — ${user.position}`
    option.selected = option.value === current
    select.append(option)
  }
  select.addEventListener('change', () => {
    browserWindow.localStorage.setItem(storageKey, select.value)
    browserWindow.location.reload()
  })
  label.append(labelText, select)
  panel.append(label)

  const guideButton = document.createElement('button')
  guideButton.type = 'button'
  guideButton.className = 'development-tools-guide'
  const syncGuideButton = (guideIsOpen = new URL(browserWindow.location.href).pathname.replace(/\/+$/, '') === '/review-guide') => {
    guideButton.textContent = guideIsOpen ? 'Портал' : 'Обзор'
  }
  syncGuideButton()
  const guideNavigationController = new AbortController()
  guideNavigationStates.set(browserWindow, { controller: guideNavigationController, panel })
  const listenerOptions = { signal: guideNavigationController.signal }
  browserWindow.addEventListener('ic:open-review-guide', () => syncGuideButton(true), listenerOptions)
  browserWindow.addEventListener('ic:close-review-guide', () => syncGuideButton(false), listenerOptions)
  browserWindow.addEventListener('popstate', () => syncGuideButton(), listenerOptions)
  guideButton.addEventListener('click', () => {
    const currentlyOpen = new URL(browserWindow.location.href).pathname.replace(/\/+$/, '') === '/review-guide'
    browserWindow.dispatchEvent(new CustomEvent(currentlyOpen ? 'ic:close-review-guide' : 'ic:open-review-guide'))
  })
  if (users.find(user => String(user.id) === current)?.roles?.includes('administrator')) {
    const seedButton = document.createElement('button')
    seedButton.type = 'button'
    seedButton.className = 'development-tools-seed'
    seedButton.textContent = 'Заполнить данные'
    seedButton.addEventListener('click', () => {
      browserWindow.dispatchEvent(new CustomEvent('ic:request-demo-seed'))
    })
    panel.append(seedButton)
  }
  panel.append(guideButton)

  const target = document.getElementById?.('ic-development-tools-slot') || document.body
  target.append(panel)
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
