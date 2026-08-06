export const REVIEW_GUIDE_PATH = '/review-guide'
export const REVIEW_GUIDE_STORAGE_KEY = 'ic.reviewGuide.v1'

export function isReviewGuidePath(location = window.location) {
  return location.pathname.replace(/\/+$/, '') === REVIEW_GUIDE_PATH
}

function availableStorage(storage) {
  if (storage) return storage
  try {
    return window.localStorage
  } catch {
    return null
  }
}

export function readReviewGuideProgress(storage) {
  try {
    const value = JSON.parse(availableStorage(storage)?.getItem(REVIEW_GUIDE_STORAGE_KEY) || '{}')
    return {
      completed: Array.isArray(value.completed) ? value.completed.filter(item => typeof item === 'string') : [],
      context: value.context && typeof value.context === 'object' ? value.context : null,
    }
  } catch {
    return { completed: [], context: null }
  }
}

export function writeReviewGuideProgress(value, storage) {
  try {
    const target = availableStorage(storage)
    if (!target) return false
    target.setItem(REVIEW_GUIDE_STORAGE_KEY, JSON.stringify(value))
    return true
  } catch {
    return false
  }
}

export function reviewGuideHref(location = window.location) {
  return `${REVIEW_GUIDE_PATH}${location.search}`
}
