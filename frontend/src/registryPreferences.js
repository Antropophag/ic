export const REGISTRY_PAGE_SIZE_KEY = 'ic.requestRegistry.pageSize.v1'
export const REGISTRY_PAGE_SIZES = Object.freeze([20, 50, 100])
export const DEFAULT_REGISTRY_PAGE_SIZE = 20

export function readRegistryPageSize(storage = window.localStorage) {
  try {
    const value = Number(storage.getItem(REGISTRY_PAGE_SIZE_KEY))
    return REGISTRY_PAGE_SIZES.includes(value) ? value : DEFAULT_REGISTRY_PAGE_SIZE
  } catch {
    return DEFAULT_REGISTRY_PAGE_SIZE
  }
}

export function writeRegistryPageSize(value, storage = window.localStorage) {
  if (!REGISTRY_PAGE_SIZES.includes(value)) return false
  try {
    storage.setItem(REGISTRY_PAGE_SIZE_KEY, String(value))
    return true
  } catch {
    return false
  }
}

export function notificationCursorKey(userId) {
  return `ic.requestRegistry.notifications.${userId}.v1`
}

export function readNotificationCursor(userId, storage = window.localStorage) {
  try {
    const value = storage.getItem(notificationCursorKey(userId))
    return /^\d{4}-\d{2}-\d{2}T/.test(value || '') ? value : ''
  } catch {
    return ''
  }
}

export function writeNotificationCursor(userId, value, storage = window.localStorage) {
  if (!/^\d{4}-\d{2}-\d{2}T/.test(value || '')) return false
  try {
    storage.setItem(notificationCursorKey(userId), value)
    return true
  } catch {
    return false
  }
}
