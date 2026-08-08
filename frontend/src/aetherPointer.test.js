import { describe, expect, it } from 'vitest'
import { createAetherPointer } from './aetherPointer'

describe('aether pointer tracking', () => {
  it('recalculates a stationary pointer after the canvas moves', () => {
    const pointer = createAetherPointer()
    pointer.resize({ left: 100, top: 50, width: 400, height: 200 })
    pointer.move(300, 150)

    expect(pointer.state).toMatchObject({ targetX: 0, targetY: 0 })

    pointer.resize({ left: 100, top: 0, width: 400, height: 200 })

    expect(pointer.state).toMatchObject({ targetX: 0, targetY: 50 })
  })

  it('forgets the last client coordinates when reset', () => {
    const pointer = createAetherPointer()
    pointer.resize({ left: 0, top: 0, width: 400, height: 200 })
    pointer.move(300, 150)
    pointer.reset()
    pointer.resize({ left: 0, top: 50, width: 400, height: 200 })

    expect(pointer.state).toMatchObject({ targetX: 0, targetY: 0 })
  })
})
