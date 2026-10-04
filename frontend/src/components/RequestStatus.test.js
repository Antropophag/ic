// @vitest-environment happy-dom
import { createApp, h } from 'vue'
import { expect, it } from 'vitest'
import RequestStatus from './RequestStatus.vue'

it.each([
  'Заявка зарегистрирована', 'Заявка в работе', 'Работы приостановлены',
  'Подготовка заключения', 'Контроль СБ', 'Заявка выполнена', 'Отказано', 'Заявка отозвана',
])('renders %s as a neutral text label without a colored marker', label => {
  const root = document.createElement('div')
  const app = createApp({ render: () => h(RequestStatus, { label }) })
  app.mount(root)
  expect(root.querySelector('.shlz-status--neutral').textContent).toBe(label)
  expect(root.querySelector('.shlz-status').title).toBe(label)
  expect(root.querySelector('.shlz-badge-dot,.request-status-dot')).toBeNull()
  app.unmount()
})
