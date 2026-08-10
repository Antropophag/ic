import { describe, expect, it } from 'vitest'
import { shlzStatusToneClass } from './shlzRegistry'

describe('SHLZ registry status mapping', () => {
  it('maps IC tones to public SHLZ status classes', () => {
    expect(shlzStatusToneClass('blue')).toBe('shlz-status--source-blue')
    expect(shlzStatusToneClass('violet')).toBe('shlz-status--purple')
    expect(shlzStatusToneClass('gray')).toBe('shlz-status--neutral')
  })

  it('keeps missing product tones application-local', () => {
    expect(shlzStatusToneClass('red')).toBe('shlz-status--ic-danger')
    expect(shlzStatusToneClass('yellow')).toBe('shlz-status--ic-warning')
    expect(shlzStatusToneClass('unknown')).toBe('shlz-status--neutral')
  })
})
