// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it } from 'vitest'
import RequestHeading from './RequestHeading.vue'

let app
afterEach(() => {
  app?.unmount()
  document.body.replaceChildren()
})

function mount(request, ready = ref(true), edit = () => {}) {
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(RequestHeading, { request, actionsReady: ready.value, onEditDepartment: edit }) })
  app.mount(root)
  return root
}

it('keeps missing identity fields explicit and the request number available', () => {
  const root = mount({ id: '000123', date: '05.10.2026' })
  expect(root.querySelector('h1').textContent).toBe('Заявка №000123 от 05.10.2026')
  for (const text of ['Инициатор не указан', 'Должность не указана', 'Подразделение не указано']) {
    expect(root.textContent).toContain(text)
  }
  expect(root.querySelector('[aria-label="Изменить подразделение заявки"]')).toBeNull()
})

it('withdraws the edit action while details are stale and restores it only when ready', async () => {
  const ready = ref(true)
  let edits = 0
  const root = mount({
    id: '000123', date: '05.10.2026', canEditDepartment: true,
    initiator: 'Тестов Иван Иванович', initiatorPosition: 'Инженер', department: 'Тестовое подразделение',
  }, ready, () => { edits += 1 })
  const selector = '[aria-label="Изменить подразделение заявки"]'
  root.querySelector(selector).click()
  expect(edits).toBe(1)
  ready.value = false
  await nextTick()
  expect(root.querySelector(selector)).toBeNull()
  expect(root.textContent).toContain('Тестов Иван Иванович')
  expect(root.textContent).toContain('Инженер')
  expect(root.textContent).toContain('Тестовое подразделение')
  ready.value = true
  await nextTick()
  expect(root.querySelector(selector)).not.toBeNull()
})
