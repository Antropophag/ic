export function createAetherPointer() {
  const state = { x: 0, y: 0, targetX: 0, targetY: 0 }
  let clientX = null
  let clientY = null
  let bounds = { left: 0, top: 0, width: 0, height: 0 }

  const updateTarget = () => {
    if (clientX === null || clientY === null) return
    state.targetX = clientX - bounds.left - bounds.width / 2
    state.targetY = clientY - bounds.top - bounds.height / 2
  }

  return {
    state,
    move(x, y) {
      if (!Number.isFinite(x) || !Number.isFinite(y)) return
      clientX = x
      clientY = y
      updateTarget()
    },
    resize(nextBounds) {
      bounds = nextBounds
      updateTarget()
    },
    reset() {
      clientX = null
      clientY = null
      state.targetX = 0
      state.targetY = 0
    },
  }
}
