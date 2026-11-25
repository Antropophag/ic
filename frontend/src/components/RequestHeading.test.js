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
  expect(root.textContent).toContain('Тестова Ивана Ивановича')
  expect(root.textContent).toContain('инженера')
  expect(root.textContent).toContain('Тестовое подразделение')
  ready.value = true
  await nextTick()
  expect(root.querySelector(selector)).not.toBeNull()
})

it('shows the same two-line reference as copying and keeps department separate', () => {
  const request = {
    id: '000123', date: '05.10.2026', backendId: 123,
    initiator: 'Тестов Иван Иванович', initiatorPosition: 'Инженер', department: 'Испытательный центр',
  }
  const root = mount(request)
  expect(root.querySelector('.request-heading-initiator').textContent.trim()).toBe('от инженера Тестова Ивана Ивановича')
  expect(root.querySelector('.request-heading-department').textContent).toContain('Подразделение:')
  expect(root.querySelector('.request-heading-department p').textContent).toBe('Испытательный центр')
  expect(root.querySelector('.request-heading-initiator').contains(root.querySelector('.request-heading-department'))).toBe(false)
  expect(request.initiator).toBe('Тестов Иван Иванович')
  expect(request.initiatorPosition).toBe('Инженер')
})

it.each([
  ['Саша Ким', 'Инженер', 'от инженера Саша Ким', null],
  ['', 'Инженер', 'от инженера', 'Инициатор не указан'],
  ['Тестов Иван Иванович', '', 'от Тестова Ивана Ивановича', 'Должность не указана'],
])('preserves ambiguous and incomplete identity: %s / %s', (initiator, initiatorPosition, expected, missing) => {
  const root = mount({ id: '000123', date: '05.10.2026', initiator, initiatorPosition })
  expect(root.querySelector('.request-heading-initiator > p').textContent).toBe(expected)
  if (missing) expect(root.querySelector('.request-heading-initiator').textContent).toContain(missing)
})
