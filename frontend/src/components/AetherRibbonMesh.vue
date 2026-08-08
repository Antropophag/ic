<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { createAetherPointer } from '../aetherPointer'

const canvas = ref(null)
let cleanup = () => {}

onMounted(() => {
  const element = canvas.value
  const context = element?.getContext('2d')
  if (!element || !context) return

  const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  const pointerTracker = createAetherPointer()
  const pointer = pointerTracker.state
  const layers = [
    { count: 15, step: 5, offset: 0, frequency: 0.0035, amplitude: 52, speed: 1.05, alpha: 0.58, width: 1.35 },
    { count: 9, step: 7, offset: 46, frequency: 0.0075, amplitude: 28, speed: 0.65, alpha: 0.2, width: 0.8 },
  ]
  let gradients = []
  let frame = 0
  let originFrame = 0
  let width = 0
  let height = 0
  let previousTime = performance.now()
  let time = 0

  const resize = () => {
    const bounds = element.getBoundingClientRect()
    const dpr = Math.min(window.devicePixelRatio || 1, 2)
    width = bounds.width
    height = bounds.height
    pointerTracker.resize(bounds)
    element.width = Math.round(width * dpr)
    element.height = Math.round(height * dpr)
    context.setTransform(dpr, 0, 0, dpr, 0, 0)
    gradients = layers.map(() => {
      const gradient = context.createLinearGradient(0, 0, width, 0)
      gradient.addColorStop(0, 'rgba(37, 61, 152, 0)')
      gradient.addColorStop(0.42, 'rgba(37, 61, 152, 0.72)')
      gradient.addColorStop(0.62, 'rgba(22, 39, 115, 0.68)')
      gradient.addColorStop(1, 'rgba(9, 25, 43, 0)')
      return gradient
    })
    if (motionQuery.matches) draw(performance.now())
  }

  const movePointer = event => {
    const point = event.touches?.[0] || event
    pointerTracker.move(point.clientX, point.clientY)
  }

  const resetPointer = () => {
    pointerTracker.reset()
  }

  const refreshOrigin = () => {
    originFrame = 0
    pointerTracker.resize(element.getBoundingClientRect())
  }

  const scheduleOriginRefresh = () => {
    if (originFrame) return
    originFrame = requestAnimationFrame(refreshOrigin)
  }

  const noise = (x, elapsed, offset) => (
    Math.sin(x * 0.0012 + elapsed * 0.25 + offset)
    + Math.cos(x * 0.0028 - elapsed * 0.4 + offset * 2)
  ) / 2

  function draw(now) {
    const delta = Math.min((now - previousTime) / 1000, 0.1)
    previousTime = now
    if (!motionQuery.matches) time += delta * 0.7

    const interpolation = 1 - Math.exp(-8 * delta)
    pointer.x += (pointer.targetX - pointer.x) * interpolation
    pointer.y += (pointer.targetY - pointer.y) * interpolation
    context.clearRect(0, 0, width, height)

    for (const [layerIndex, layer] of layers.entries()) {
      context.strokeStyle = gradients[layerIndex]

      for (let ribbon = 0; ribbon < layer.count; ribbon += 1) {
        const progress = ribbon / layer.count
        const baseY = height * 0.25 + ribbon * height * 0.035 + layer.offset
        context.beginPath()
        for (let x = 0; x <= width + layer.step; x += layer.step) {
          const envelope = Math.sin((x / width) * Math.PI)
          const frequency = 1 + noise(x, time, progress) * 0.18
          const amplitude = 1 + noise(x * 2, -time, progress * 0.5) * 0.15
          const cursorDistance = Math.abs(x - (width / 2 + pointer.x))
          const cursorInfluence = Math.exp(-Math.pow(cursorDistance / 340, 2))
          const y = baseY
            + Math.sin(x * layer.frequency * frequency + time * layer.speed + ribbon * 0.18) * layer.amplitude * envelope * amplitude
            + Math.cos(x * 0.008 - time * 0.7 + ribbon * 0.1) * 18 * envelope
            + Math.sin(x * 0.015 + time * 2.2) * cursorInfluence * 34 * envelope
            + pointer.y * progress * 0.07
          if (x === 0) context.moveTo(x, y)
          else context.lineTo(x, y)
        }
        context.globalAlpha = (1 - progress * 0.74) * layer.alpha
        context.lineWidth = layer.width + (1 - progress) * 0.45
        context.stroke()
      }
    }

    context.globalAlpha = 1
    if (!motionQuery.matches && !document.hidden) frame = requestAnimationFrame(draw)
  }

  const syncMotion = () => {
    cancelAnimationFrame(frame)
    previousTime = performance.now()
    if (motionQuery.matches) draw(previousTime)
    else frame = requestAnimationFrame(draw)
  }
  const syncVisibility = () => {
    cancelAnimationFrame(frame)
    if (!document.hidden) syncMotion()
  }
  const resizeObserver = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(resize)

  resize()
  syncMotion()
  if (resizeObserver) resizeObserver.observe(element)
  else window.addEventListener('resize', resize)
  window.addEventListener('mousemove', movePointer)
  window.addEventListener('touchmove', movePointer, { passive: true })
  window.addEventListener('touchend', resetPointer)
  window.addEventListener('touchcancel', resetPointer)
  document.documentElement.addEventListener('mouseleave', resetPointer)
  document.addEventListener('scroll', scheduleOriginRefresh, true)
  document.addEventListener('visibilitychange', syncVisibility)
  motionQuery.addEventListener('change', syncMotion)

  cleanup = () => {
    cancelAnimationFrame(frame)
    cancelAnimationFrame(originFrame)
    if (resizeObserver) resizeObserver.disconnect()
    else window.removeEventListener('resize', resize)
    window.removeEventListener('mousemove', movePointer)
    window.removeEventListener('touchmove', movePointer)
    window.removeEventListener('touchend', resetPointer)
    window.removeEventListener('touchcancel', resetPointer)
    document.documentElement.removeEventListener('mouseleave', resetPointer)
    document.removeEventListener('scroll', scheduleOriginRefresh, true)
    document.removeEventListener('visibilitychange', syncVisibility)
    motionQuery.removeEventListener('change', syncMotion)
  }
})

onBeforeUnmount(() => cleanup())
</script>

<template>
  <canvas ref="canvas" class="aether-ribbon-mesh" aria-hidden="true" />
</template>
