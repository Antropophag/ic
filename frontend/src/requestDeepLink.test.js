import { describe, expect, it, vi } from 'vitest'
import { requestIdFromLocation, setRequestInUrl } from './requestDeepLink'

describe('request deep links', () => {
  it('reads only positive numeric request ids', () => {
    expect(requestIdFromLocation({ search: '?request=42' })).toBe(42)
    expect(requestIdFromLocation({ search: '?request=abc' })).toBeNull()
    expect(requestIdFromLocation({ search: '?request=-1' })).toBeNull()
  })

  it('adds and removes the request parameter while preserving others', () => {
    const replaceState = vi.fn()
    const location = { href: 'https://portal.test/?source=email#top' }
    setRequestInUrl(7, { replaceState }, location)
    expect(replaceState).toHaveBeenLastCalledWith({}, '', '/?source=email&request=7#top')
    setRequestInUrl(null, { replaceState }, { href: 'https://portal.test/?source=email&request=7#top' })
    expect(replaceState).toHaveBeenLastCalledWith({}, '', '/?source=email#top')
  })
})
