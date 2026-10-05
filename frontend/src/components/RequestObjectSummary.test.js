// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it } from 'vitest'
import RequestObjectSummary from './RequestObjectSummary.vue'

let app
afterEach(() => {
  app?.unmount()
  document.body.replaceChildren()
})
const disclosure = root => root.querySelector(`[aria-controls="${root.querySelector('.request-objects-list').id}"]`)
const objects = count => Array.from({ length: count }, (_, index) => ({ name: `Тестовый объект ${index + 1}`, sampleQuantityText: '4 шт по 3 метра' }))
function mount(count, roles = ref([]), identity = ref(1)) {
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(RequestObjectSummary, {
    key: identity.value, objects: objects(count), roles: roles.value,
    manufacturer: 'Общий производитель', supplier: 'Общий поставщик', scope: 'Общий объём испытаний',
  }) })
  app.mount(root)
  return root
}

it('shows one object without a disclosure and keeps its quantity out of the shared fields', () => {
  const root = mount(1)
  expect(disclosure(root)).toBeNull()
  expect(root.querySelector('[aria-label="Копировать все образцы и поставщика"]')).toBeNull()
  expect(root.querySelector('.request-objects-list').hidden).toBe(false)
  expect(root.querySelector('tbody').textContent).toContain('4 шт по 3 метра')
  expect(root.querySelector('.request-overview').textContent).not.toContain('4 шт по 3 метра')
  expect(root.querySelector('.request-overview').textContent).toContain('Общий производитель')
})

it.each([3, 10])('shows the object count %i, not a sum of quantities, and provides a reversible disclosure', async count => {
  const root = mount(count)
  const trigger = disclosure(root)
  expect(root.querySelector('h2').textContent).toBe(`Объекты испытаний · ${count}`)
  expect(trigger.getAttribute('aria-expanded')).toBe('false')
  expect(root.querySelector('.request-objects-list').hidden).toBe(true)
  trigger.click()
  await nextTick()
  expect(root.querySelector('.request-objects-list').hidden).toBe(false)
  expect(root.querySelectorAll('tbody tr')).toHaveLength(count)
  trigger.click()
  await nextTick()
  expect(root.querySelector('.request-objects-list').hidden).toBe(true)
})

it.each([
  [['ic_manager'], true], [['laboratory_manager'], true], [['ic_executor'], true],
  [['employee'], false], [['expert'], false], [['security_officer'], false],
  [['expert', 'ic_executor'], true],
])('uses the initial disclosure for roles %j', (roles, expanded) => {
  const root = mount(3, ref(roles))
  expect(disclosure(root).getAttribute('aria-expanded')).toBe(String(expanded))
})

it('resets the disclosure when changing the viewing role or reopening the card', async () => {
  const roles = ref(['employee'])
  const identity = ref(1)
  const root = mount(3, roles, identity)
  disclosure(root).click()
  await nextTick()
  identity.value = 2
  await nextTick()
  expect(root.querySelector('.request-objects-list').hidden).toBe(true)
  roles.value = ['ic_manager']
  await nextTick()
  expect(root.querySelector('.request-objects-list').hidden).toBe(false)
})
