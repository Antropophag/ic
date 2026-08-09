// @vitest-environment happy-dom

import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AetherRibbonMesh from './AetherRibbonMesh.vue'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.replaceChildren()
})

function mountRibbon() {
  const gradients = []
  const context = {
    beginPath: vi.fn(),
    clearRect: vi.fn(),
    createLinearGradient: vi.fn(() => {
      const gradient = { addColorStop: vi.fn() }
      gradients.push(gradient)
      return gradient
    }),
    lineTo: vi.fn(),
    moveTo: vi.fn(),
    setTransform: vi.fn(),
    stroke: vi.fn(),
    strokeStyle: null,
  }
  const motionListeners = new Set()
  const motionQuery = {
    matches: false,
    addEventListener: vi.fn((type, listener) => motionListeners.add(listener)),
    removeEventListener: vi.fn((type, listener) => motionListeners.delete(listener)),
    dispatchChange: () => motionListeners.forEach(listener => listener()),
  }
  const frames = new Map()
  let nextFrame = 1

  vi.spyOn(window, 'matchMedia').mockReturnValue(motionQuery)
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context)
  vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockReturnValue({
    bottom: 480,
    height: 480,
    left: 0,
    right: 640,
    top: 0,
    width: 640,
    x: 0,
    y: 0,
  })
  vi.stubGlobal('requestAnimationFrame', vi.fn(callback => {
    const id = nextFrame
    nextFrame += 1
    frames.set(id, callback)
    return id
  }))
  vi.stubGlobal('cancelAnimationFrame', vi.fn(id => frames.delete(id)))
  vi.stubGlobal('ResizeObserver', undefined)

  const error = ref(false)
  const app = createApp({ render: () => h(AetherRibbonMesh, { error: error.value }) })
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)

  const runFrame = (now) => {
    const callbacks = [...frames.values()]
    frames.clear()
    callbacks.forEach(callback => callback(now))
  }

  return { app, context, error, frames, gradients, motionQuery, runFrame }
}

describe('AetherRibbonMesh', () => {
  it('transitions from blue to red and back while caching stable gradients', async () => {
    const ribbon = mountRibbon()

    expect(ribbon.gradients).toHaveLength(2)
    ribbon.runFrame(performance.now() + 16)
    expect(ribbon.context.strokeStyle).toBe(ribbon.gradients[0])
    expect(ribbon.gradients).toHaveLength(2)

    ribbon.error.value = true
    await nextTick()
    let now = performance.now()
    for (let index = 0; index < 80; index += 1) {
      now += 100
      ribbon.runFrame(now)
    }
    expect(ribbon.context.strokeStyle).toBe(ribbon.gradients[1])
    const redGradientCount = ribbon.gradients.length
    ribbon.runFrame(now + 100)
    expect(ribbon.gradients).toHaveLength(redGradientCount)

    ribbon.error.value = false
    await nextTick()
    for (let index = 0; index < 80; index += 1) {
      now += 100
      ribbon.runFrame(now)
    }
    expect(ribbon.context.strokeStyle).toBe(ribbon.gradients[0])

    ribbon.app.unmount()
  })

  it('snaps an active transition when reduced motion is enabled and cleans up', async () => {
    const ribbon = mountRibbon()
    ribbon.error.value = true
    await nextTick()
    ribbon.runFrame(performance.now() + 16)

    ribbon.motionQuery.matches = true
    ribbon.motionQuery.dispatchChange()

    expect(ribbon.context.strokeStyle).toBe(ribbon.gradients[1])
    expect(ribbon.frames.size).toBe(0)
    expect(ribbon.motionQuery.removeEventListener).not.toHaveBeenCalled()

    ribbon.motionQuery.matches = false
    ribbon.motionQuery.dispatchChange()
    expect(ribbon.frames.size).toBe(1)
    ribbon.app.unmount()

    expect(ribbon.frames.size).toBe(0)
    expect(ribbon.motionQuery.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function))
  })
})
