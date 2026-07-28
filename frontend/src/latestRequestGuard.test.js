import { describe, expect, it } from 'vitest'
import { createLatestRequestGuard } from './latestRequestGuard'

describe('latest request guard', () => {
  it('rejects a response after the user closes the card', () => {
    const guard = createLatestRequestGuard()
    const request = guard.begin(10)

    guard.invalidate()

    expect(guard.isCurrent(request, 10)).toBe(false)
  })

  it('allows only the latest response when cards are opened in quick succession', () => {
    const guard = createLatestRequestGuard()
    const first = guard.begin(10)
    const second = guard.begin(11)

    expect(guard.isCurrent(first, 10)).toBe(false)
    expect(guard.isCurrent(second, 11)).toBe(true)
    expect(guard.isCurrent(second, 10)).toBe(false)
  })
})
