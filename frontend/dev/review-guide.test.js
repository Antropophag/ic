import { describe, expect, it } from 'vitest'
import {
  REVIEW_GUIDE_STORAGE_KEY,
  isReviewGuidePath,
  readReviewGuideProgress,
  reviewGuideHref,
  writeReviewGuideProgress,
} from './review-guide'

class Storage {
  value = new Map()
  getItem(key) { return this.value.get(key) ?? null }
  setItem(key, value) { this.value.set(key, String(value)) }
}

describe('review guide navigation and progress', () => {
  it('recognizes only the dedicated route', () => {
    expect(isReviewGuidePath({ pathname: '/review-guide/' })).toBe(true)
    expect(isReviewGuidePath({ pathname: '/requests' })).toBe(false)
  })

  it('preserves query parameters in a return link', () => {
    expect(reviewGuideHref({ search: '?request=42' })).toBe('/review-guide?request=42')
  })

  it('persists completed steps and navigation context', () => {
    const storage = new Storage()
    const progress = { completed: ['quick-seed'], context: { role: 'Эксперт', object: 'демо-серия 004' } }
    writeReviewGuideProgress(progress, storage)

    expect(storage.getItem(REVIEW_GUIDE_STORAGE_KEY)).toContain('quick-seed')
    expect(readReviewGuideProgress(storage)).toEqual(progress)
  })

  it('recovers from malformed or unexpected browser data', () => {
    const storage = new Storage()
    storage.setItem(REVIEW_GUIDE_STORAGE_KEY, '{broken')
    expect(readReviewGuideProgress(storage)).toEqual({ completed: [], context: null })
    storage.setItem(REVIEW_GUIDE_STORAGE_KEY, JSON.stringify({ completed: [1, 'flow-1'], context: 'bad' }))
    expect(readReviewGuideProgress(storage)).toEqual({ completed: ['flow-1'], context: null })
  })
})
