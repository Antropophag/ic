// @vitest-environment happy-dom
import { createApp, nextTick } from 'vue'
import { expect, it, vi } from 'vitest'
import AdminPanel from './AdminPanel.vue'
vi.mock('../api', () => ({ adminApi: { systemOverview: vi.fn().mockRejectedValue(new Error('offline')) } }))
it('opens Overview as the first admin tab', async () => {
  const root = document.createElement('div'); document.body.append(root); const app = createApp(AdminPanel); app.mount(root); await Promise.resolve(); await nextTick()
  const tabs = [...root.querySelectorAll('[role=tab]')]
  expect(tabs.map(item => item.textContent)).toEqual(['Обзор', 'Пользователи и роли', 'Журнал действий', 'Уведомления'])
  expect(tabs[0].getAttribute('aria-selected')).toBe('true'); app.unmount(); root.remove()
})
