import { describe, expect, it } from 'vitest'
import {
  isReviewGuidePath,
  reviewGuideHref,
} from './review-guide'

describe('review guide navigation', () => {
  it('recognizes only the dedicated route', () => {
    expect(isReviewGuidePath({ pathname: '/review-guide/' })).toBe(true)
    expect(isReviewGuidePath({ pathname: '/requests' })).toBe(false)
  })

  it('preserves query parameters in a return link', () => {
    expect(reviewGuideHref({ search: '?request=42' })).toBe('/review-guide?request=42')
  })
})
