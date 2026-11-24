// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import CopyButton from './CopyButton.vue'

let app
afterEach(() => {
  app?.unmount()
  document.body.replaceChildren()
  vi.restoreAllMocks()
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

it('keeps exact manual copy text available until dismissed', async () => {
  vi.useFakeTimers()
  vi.stubGlobal('navigator', { clipboard: undefined })
  const root = await mount(ref('Заявка №123\nот инженера Иванова'))
  root.querySelector('button').click()
  await nextTick()
  await vi.advanceTimersByTimeAsync(60000)
  const field = document.querySelector('[aria-label="Текст для ручного копирования"]')
  expect(field.value).toBe('Заявка №123\nот инженера Иванова')
  field.focus()
  expect(field.selectionStart).toBe(0)
  expect(field.selectionEnd).toBe(field.value.length)
  document.querySelector('[aria-label="Закрыть уведомление о копировании"]').click()
  await nextTick()
  expect(document.querySelector('[role="alert"]')).toBeNull()
})

it.each([undefined, { writeText: vi.fn().mockRejectedValue(new Error('denied')) }])('copies with selection when Clipboard API is unavailable or denied', async clipboard => {
  vi.stubGlobal('navigator', { clipboard })
  const exec = vi.fn(() => {
    expect(document.activeElement.value).toBe('Объект испытаний')
    expect(document.activeElement.selectionEnd).toBe('Объект испытаний'.length)
    return true
  })
  const original = document.execCommand
  document.execCommand = exec
  try {
    const root = await mount()
    root.querySelector('button').focus()
    root.querySelector('button').click()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Наименование скопировано'))
    expect(exec).toHaveBeenCalledExactlyOnceWith('copy')
    expect(document.querySelector('textarea')).toBeNull()
    expect(document.activeElement).toBe(root.querySelector('button'))
  } finally {
    document.execCommand = original
  }
})

it('does not copy old text through fallback after a late denial', async () => {
  let reject
  vi.stubGlobal('navigator', { clipboard: { writeText: () => new Promise((_, fail) => { reject = fail }) } })
  const original = document.execCommand
  document.execCommand = vi.fn(() => true)
  try {
    const text = ref('Первый объект')
    const root = await mount(text)
    root.querySelector('button').click()
    text.value = 'Другой объект'
    await nextTick()
    reject(new Error('denied'))
    await nextTick()
    expect(document.execCommand).not.toHaveBeenCalled()
    expect(document.querySelector('[role="alert"]')).toBeNull()
  } finally { document.execCommand = original }
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
