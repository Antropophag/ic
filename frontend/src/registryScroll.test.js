// @vitest-environment happy-dom
import { expect, it, vi } from 'vitest'
import { scrollRegistryTable } from './registryScroll'

function input(key = 'ArrowDown', target = document.createElement('button')) {
  return { key, target, preventDefault: vi.fn() }
}

it.each([
  ['ArrowDown', 140, 100], ['ArrowUp', 60, 100], ['ArrowRight', 100, 140],
  ['ArrowLeft', 100, 60], ['PageDown', 400, 100], ['PageUp', -200, 100],
])('scrolls the registry on %s from a focused control', (key, top, left) => {
  const box = { scrollTop: 100, scrollLeft: 100, clientHeight: 300 }
  const event = input(key)
  scrollRegistryTable(box, event)
  expect([box.scrollTop, box.scrollLeft]).toEqual([top, left])
  expect(event.preventDefault).toHaveBeenCalledOnce()
})

it.each(['altKey', 'ctrlKey', 'metaKey', 'shiftKey'])('preserves %s shortcuts', modifier => {
  const box = { scrollTop: 100, scrollLeft: 100, clientHeight: 300 }
  const event = { ...input(), [modifier]: true }
  scrollRegistryTable(box, event)
  expect(box.scrollTop).toBe(100)
  expect(event.preventDefault).not.toHaveBeenCalled()
})

it.each(['input', 'select', 'textarea', 'div'])('leaves %s editing keys alone', tag => {
  const target = document.createElement(tag)
  if (tag === 'div') target.setAttribute('contenteditable', 'true')
  const event = input('ArrowDown', target)
  const box = { scrollTop: 100, scrollLeft: 100, clientHeight: 300 }
  scrollRegistryTable(box, event)
  expect(box.scrollTop).toBe(100)
  expect(event.preventDefault).not.toHaveBeenCalled()
})

it('leaves unknown keys, missing containers and exhausted scrolling to the browser', () => {
  const event = input('Enter')
  const box = { scrollTop: 0, scrollLeft: 0, clientHeight: 300 }
  scrollRegistryTable(box, event)
  scrollRegistryTable(null, input())
  const boundary = { clientHeight: 300, get scrollTop() { return 0 }, set scrollTop(_value) {}, get scrollLeft() { return 0 }, set scrollLeft(_value) {} }
  const up = input('ArrowUp')
  scrollRegistryTable(boundary, up)
  expect(event.preventDefault).not.toHaveBeenCalled()
  expect(up.preventDefault).not.toHaveBeenCalled()
})
