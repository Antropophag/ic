class MemoryStorage {
  #values = new Map()

  getItem(key) {
    return this.#values.get(String(key)) ?? null
  }

  setItem(key, value) {
    this.#values.set(String(key), String(value))
  }
}

class TestElement extends EventTarget {
  constructor(tagName) {
    super()
    this.tagName = tagName.toUpperCase()
    this.children = []
    this.style = { cssText: '' }
    this.textContent = ''
    this.value = ''
    this.selected = false
    this.attributes = new Map()
  }

  append(...children) {
    this.children.push(...children)
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value))
  }
}

export function createDevToolsBrowserEnvironment({ fetch, readyState = 'complete', reload = () => {} } = {}) {
  if (typeof fetch !== 'function') throw new TypeError('fetch must be a function')

  const events = new EventTarget()
  let currentReadyState = readyState
  const document = {
    body: new TestElement('body'),
    createElement: (tagName) => new TestElement(tagName),
    get readyState() {
      return currentReadyState
    },
  }
  const browserWindow = {
    fetch,
    localStorage: new MemoryStorage(),
    location: {
      href: 'http://localhost:8080/requests',
      origin: 'http://localhost:8080',
      reload,
    },
    addEventListener: events.addEventListener.bind(events),
    removeEventListener: events.removeEventListener.bind(events),
    dispatchEvent: events.dispatchEvent.bind(events),
  }

  return {
    browserWindow,
    document,
    dispatchDOMContentLoaded() {
      currentReadyState = 'interactive'
      browserWindow.dispatchEvent(new Event('DOMContentLoaded'))
    },
  }
}
