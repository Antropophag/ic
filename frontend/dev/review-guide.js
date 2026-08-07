export const REVIEW_GUIDE_PATH = '/review-guide'

export function isReviewGuidePath(location = window.location) {
  return location.pathname.replace(/\/+$/, '') === REVIEW_GUIDE_PATH
}

export function reviewGuideHref(location = window.location) {
  return `${REVIEW_GUIDE_PATH}${location.search}`
}
