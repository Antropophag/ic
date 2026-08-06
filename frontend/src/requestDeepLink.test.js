import { describe, expect, it, vi } from 'vitest'
import { requestIdFromLocation, resolveRequestDeepLink, setRequestInUrl } from './requestDeepLink'

describe('request deep links', () => {
  it('reads only positive numeric request ids', () => {
    expect(requestIdFromLocation({ search: '?request=42' })).toBe(42)
    expect(requestIdFromLocation({ search: '?request=abc' })).toBeNull()
    expect(requestIdFromLocation({ search: '?request=-1' })).toBeNull()
  })

  it('adds and removes the request parameter while preserving others', () => {
    const replaceState = vi.fn()
    const history = { replaceState }
    const location = { href: 'https://portal.test/?source=email#top' }
    setRequestInUrl(7, { history, location })
    expect(replaceState).toHaveBeenLastCalledWith({}, '', '/?source=email&request=7#top')
    setRequestInUrl(null, { history, location: { href: 'https://portal.test/?source=email&request=7#top' } })
    expect(replaceState).toHaveBeenLastCalledWith({}, '', '/?source=email#top')
  })

  it('pushes a history entry instead of replacing when push is requested', () => {
    const pushState = vi.fn()
    const replaceState = vi.fn()
    const history = { pushState, replaceState }
    const location = { href: 'https://portal.test/' }
    setRequestInUrl(9, { push: true, history, location })
    expect(pushState).toHaveBeenLastCalledWith({}, '', '/?request=9')
    expect(replaceState).not.toHaveBeenCalled()
  })

  it('normalizes request links created from the review guide', () => {
    const replaceState = vi.fn()
    setRequestInUrl(12, {
      history: { replaceState },
      location: { href: 'https://portal.test/review-guide?source=guide' },
    })

    expect(replaceState).toHaveBeenCalledWith({}, '', '/?source=guide&request=12')
  })

  it('loads a linked request directly when it is outside the registry result', async () => {
    const getRequest = vi.fn().mockResolvedValue({ item: { id: 501 }, history: [], comments: [], documents: [] })

    await expect(resolveRequestDeepLink(501, [{ backendId: 1 }], getRequest)).resolves.toEqual({
      item: { id: 501 },
      detail: { item: { id: 501 }, history: [], comments: [], documents: [] },
    })
    expect(getRequest).toHaveBeenCalledWith(501)
  })

  it('reuses a request already present in the registry result', async () => {
    const item = { backendId: 42 }
    const getRequest = vi.fn()

    await expect(resolveRequestDeepLink(42, [item], getRequest)).resolves.toEqual({ item, detail: null })
    expect(getRequest).not.toHaveBeenCalled()
  })
})
