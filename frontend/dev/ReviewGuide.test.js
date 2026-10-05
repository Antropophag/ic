// @vitest-environment happy-dom

import { createApp } from 'vue'
import { expect, it, vi } from 'vitest'
import ReviewGuide from './ReviewGuide.vue'

it('explains the two routes and returns through the portal navigation event', () => {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp(ReviewGuide)
  const close = vi.fn()
  window.addEventListener('ic:close-review-guide', close)
  try {
    app.mount(root)
    const titles = id => [...root.querySelectorAll(`[aria-labelledby="${id}"] .route-step b`)].map(element => element.textContent)
    expect(titles('act-route-title')).toEqual(['Регистрация', 'Маршрут и исполнитель', 'Испытания и отчёт', 'Завершение'])
    expect(titles('protocol-route-title')).toEqual(['Регистрация', 'Маршрут и исполнитель', 'Испытания и отчёт', 'Экспертиза', 'Контроль СБ', 'Завершение'])
    root.querySelector('.guide-footer button').click()
    expect(close).toHaveBeenCalledOnce()
  } finally {
    app.unmount()
    root.remove()
    window.removeEventListener('ic:close-review-guide', close)
  }
})
