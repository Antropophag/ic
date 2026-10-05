// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import CopyButton from './CopyButton.vue'

let app
afterEach(() => {
  app?.unmount()
  document.body.replaceChildren()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

async function mount(text = ref('Объект испытаний')) {
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(CopyButton, { text: text.value, label: 'Копировать наименование', success: 'Наименование скопировано' }) })
  app.mount(root)
  await nextTick()
  return root
}

it('copies exactly the supplied text and reports success', async () => {
  const writeText = vi.fn().mockResolvedValue()
  vi.stubGlobal('navigator', { clipboard: { writeText } })
  const root = await mount()
  root.querySelector('button').click()
  await vi.waitFor(() => expect(document.body.textContent).toContain('Наименование скопировано'))
  expect(writeText).toHaveBeenCalledExactlyOnceWith('Объект испытаний')
  expect(document.querySelector('[role="status"]')).not.toBeNull()
})

it.each([undefined, { writeText: vi.fn().mockRejectedValue(new Error('denied')) }])('reports an unavailable or denied clipboard with a recovery action', async clipboard => {
  vi.stubGlobal('navigator', { clipboard })
  const root = await mount()
  root.querySelector('button').click()
  await vi.waitFor(() => expect(document.body.textContent).toContain('Не удалось скопировать. Выделите текст и скопируйте вручную.'))
  expect(document.querySelector('[role="alert"]')).not.toBeNull()
})

it('ignores completion for an old value after navigation', async () => {
  let resolve
  vi.stubGlobal('navigator', { clipboard: { writeText: () => new Promise(done => { resolve = done }) } })
  const text = ref('Первый объект')
  const root = await mount(text)
  root.querySelector('button').click()
  text.value = 'Второй объект'
  await nextTick()
  resolve()
  await nextTick()
  expect(document.body.textContent).not.toContain('Наименование скопировано')
  expect(root.querySelector('button').disabled).toBe(false)
})

it('ignores a late clipboard result after the card is closed', async () => {
  let resolve
  vi.stubGlobal('navigator', { clipboard: { writeText: () => new Promise(done => { resolve = done }) } })
  const root = await mount()
  root.querySelector('button').click()
  app.unmount()
  app = null
  resolve()
  await nextTick()
  expect(document.querySelector('[role="status"]')).toBeNull()
  expect(root.textContent).toBe('')
})

it('uses the library light variant and disappears without moving focus', async () => {
  vi.useFakeTimers()
  vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockResolvedValue() } })
  const root = await mount()
  root.querySelector('button').focus()
  root.querySelector('button').click()
  await nextTick()
  await nextTick()
  expect(document.querySelector('[role="status"]').classList.contains('shlz-notification--light')).toBe(true)
  await vi.advanceTimersByTimeAsync(3000)
  expect(document.querySelector('[role="status"]')).toBeNull()
  expect(document.activeElement).toBe(root.querySelector('button'))
})

it('keeps the result while hovered or focused, then dismisses automatically', async () => {
  vi.useFakeTimers()
  vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockResolvedValue() } })
  const root = await mount()
  root.querySelector('button').click()
  await nextTick()
  await nextTick()
  const result = document.querySelector('[role="status"]')
  result.dispatchEvent(new Event('mouseenter'))
  result.querySelector('button').focus()
  result.dispatchEvent(new Event('mouseleave'))
  await vi.advanceTimersByTimeAsync(10000)
  expect(document.querySelector('[role="status"]')).toBe(result)
  root.querySelector('button').focus()
  await vi.advanceTimersByTimeAsync(3000)
  expect(document.querySelector('[role="status"]')).toBeNull()
})

it('allows longer reading for errors and clears their timer on unmount', async () => {
  vi.useFakeTimers()
  vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
  const root = await mount()
  root.querySelector('button').click()
  await nextTick()
  await nextTick()
  await vi.advanceTimersByTimeAsync(3000)
  expect(document.querySelector('[role="alert"]')).not.toBeNull()
  await vi.advanceTimersByTimeAsync(7000)
  expect(document.querySelector('[role="alert"]')).toBeNull()
  root.querySelector('button').click()
  await nextTick()
  expect(vi.getTimerCount()).toBe(1)
  app.unmount()
  app = null
  expect(vi.getTimerCount()).toBe(0)
})

it('does not let an earlier timer hide a newer copy result', async () => {
  vi.useFakeTimers()
  vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockResolvedValue() } })
  const root = await mount()
  root.querySelector('button').click()
  await nextTick()
  await vi.advanceTimersByTimeAsync(2500)
  root.querySelector('button').click()
  await nextTick()
  await vi.advanceTimersByTimeAsync(500)
  expect(document.querySelector('[role="status"]')).not.toBeNull()
  await vi.advanceTimersByTimeAsync(2500)
  expect(document.querySelector('[role="status"]')).toBeNull()
})
