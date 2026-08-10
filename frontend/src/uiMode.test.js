import { describe, expect, it } from 'vitest'
import { resolveShlzMode } from './uiMode'

describe('resolveShlzMode', () => {
  it('enables SHLZ only for an explicit development query parameter', () => {
    expect(resolveShlzMode({ mode: 'development', search: '?ui=shlz' })).toBe(true)
    expect(resolveShlzMode({ mode: 'development', search: '?request=42&ui=shlz' })).toBe(true)
  })

  it('keeps the default UI for production, missing and unknown values', () => {
    expect(resolveShlzMode({ mode: 'production', search: '?ui=shlz' })).toBe(false)
    expect(resolveShlzMode({ mode: 'development', search: '?ui=ic' })).toBe(false)
    expect(resolveShlzMode({ mode: 'development', search: '' })).toBe(false)
  })
})
