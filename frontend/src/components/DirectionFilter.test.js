// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import DirectionFilter from './DirectionFilter.vue'

const mounted = []
function mountFilter(initial = []) {
  const selection = ref(initial)
  const active = ref(true)
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp({ render: () => h(DirectionFilter, {
    modelValue: selection.value, active: active.value,
    'onUpdate:modelValue': value => { selection.value = value },
  }) })
  app.mount(root)
  const panel = document.querySelector('dialog')
  const trigger = root.querySelector('button')
  // Happy DOM has no native popover lifecycle; browser e2e covers the platform itself.
  const toggle = state => {
    for (const type of ['beforetoggle', 'toggle']) {
      const event = new Event(type)
      Object.defineProperty(event, 'newState', { value: state })
      panel.dispatchEvent(event)
    }
  }
  panel.hidePopover = vi.fn(() => toggle('closed'))
  let alive = true
  const unmount = () => { if (alive) { app.unmount(); alive = false } }
  const api = {
    selection, active, panel, trigger, toggle, unmount,
    open: async () => { toggle('open'); await nextTick(); await nextTick() },
    button: text => [...panel.querySelectorAll('button')].find(button => button.textContent === text),
    check: async color => {
      const input = panel.querySelector(`input[value="${color}"]`)
      input.checked = true
      input.dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()
    },
  }
  mounted.push(api)
  return api
}
afterEach(() => {
  for (const item of mounted.splice(0)) item.unmount()
  document.body.replaceChildren()
  vi.restoreAllMocks()
})

it('keeps selections as a draft until apply and resets them explicitly', async () => {
  const ui = mountFilter(['blue'])
  window.dispatchEvent(new Event('resize'))
  await ui.open()
  await ui.check('green')
  expect(ui.selection.value).toEqual(['blue'])
  expect(ui.panel.style.visibility).toBe('visible')
  ui.button('Применить').click()
  await nextTick()
  expect(ui.selection.value).toEqual(['blue', 'green'])
  expect(ui.trigger.getAttribute('data-filter-active')).toBe('true')
  expect(ui.trigger.title).toContain('Механические испытания, Резерв 2')
  expect(document.activeElement).toBe(ui.trigger)
  await ui.open()
  ui.button('Сбросить').click()
  await nextTick()
  expect(ui.selection.value).toEqual([])
  expect(ui.trigger.getAttribute('data-filter-active')).toBe('false')
  expect(ui.trigger.title).toBe('Все направления испытаний')
})

it('cancels via Escape from body and discards a light-dismissed draft', async () => {
  const ui = mountFilter(['blue'])
  await ui.open()
  await ui.check('red')
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
  expect(ui.trigger.getAttribute('aria-expanded')).toBe('true')
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
  await nextTick()
  expect(ui.selection.value).toEqual(['blue'])
  expect(ui.trigger.getAttribute('aria-expanded')).toBe('false')
  expect(document.activeElement).toBe(ui.trigger)
  await ui.open()
  expect(ui.panel.querySelector('input[value="red"]').checked).toBe(false)
  await ui.check('orange')
  ui.toggle('closed')
  await ui.open()
  expect(ui.panel.querySelector('input[value="orange"]').checked).toBe(false)
})

it('clamps a zoomed popup near viewport edges and follows scrolling', async () => {
  const ui = mountFilter()
  const anchor = vi.spyOn(ui.trigger, 'getBoundingClientRect').mockReturnValue({
    left: window.innerWidth - 5, top: window.innerHeight - 20, bottom: window.innerHeight - 4,
  })
  const box = vi.spyOn(ui.panel, 'getBoundingClientRect').mockReturnValue({ width: 300, height: 400 })
  vi.spyOn(ui.panel, 'offsetWidth', 'get').mockReturnValue(150)
  await ui.open()
  expect(Number.parseFloat(ui.panel.style.left)).toBe((window.innerWidth - 308) / 2)
  expect(Number.parseFloat(ui.panel.style.top)).toBe((window.innerHeight - 428) / 2)
  expect(Number.parseFloat(ui.panel.style.maxBlockSize)).toBe((window.innerHeight - 16) / 2)
  anchor.mockReturnValue({ left: 2, top: 2, bottom: 18 })
  window.dispatchEvent(new Event('scroll'))
  expect(ui.panel.style.left).toBe('4px')
  expect(ui.panel.style.top).toBe('13px')
  const calls = box.mock.calls.length
  ui.unmount()
  window.dispatchEvent(new Event('resize'))
  expect(box).toHaveBeenCalledTimes(calls)
})

it('closes on leaving the registry and safely ignores a late opening callback after unmount', async () => {
  const ui = mountFilter(['blue'])
  await ui.open()
  await ui.check('green')
  ui.active.value = false
  await nextTick()
  expect(ui.panel.hidePopover).toHaveBeenCalled()
  expect(ui.selection.value).toEqual(['blue'])
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  ui.active.value = true
  await nextTick()
  ui.toggle('closed')
  ui.active.value = false
  await nextTick()
  const focus = vi.spyOn(ui.trigger, 'focus')
  ui.toggle('open')
  ui.unmount()
  await nextTick()
  expect(focus).not.toHaveBeenCalled()
})
