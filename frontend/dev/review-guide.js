export const REVIEW_GUIDE_PATH = '/review-guide'
export const REVIEW_GUIDE_STORAGE_KEY = 'ic.reviewGuide.v1'

export function isReviewGuidePath(location = window.location) {
  return location.pathname.replace(/\/+$/, '') === REVIEW_GUIDE_PATH
}

export function readReviewGuideProgress(storage = window.localStorage) {
  try {
    const value = JSON.parse(storage.getItem(REVIEW_GUIDE_STORAGE_KEY) || '{}')
    return {
      completed: Array.isArray(value.completed) ? value.completed.filter(item => typeof item === 'string') : [],
      context: value.context && typeof value.context === 'object' ? value.context : null,
    }
  } catch {
    return { completed: [], context: null }
  }
}

export function writeReviewGuideProgress(value, storage = window.localStorage) {
  storage.setItem(REVIEW_GUIDE_STORAGE_KEY, JSON.stringify(value))
}

export function reviewGuideHref(location = window.location) {
  return `${REVIEW_GUIDE_PATH}${location.search}`
}
