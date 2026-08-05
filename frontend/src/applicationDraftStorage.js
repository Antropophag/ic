const SCHEMA_VERSION = 1
const STORAGE_KEY_PREFIX = 'ic.application-create-draft.v1.'
const TTL_MS = 30 * 24 * 60 * 60 * 1000

const STRING_FIELDS = {
  productName: 500,
  manufacturer: 500,
  supplier: 500,
  testMethod: 10000,
  comment: 10000,
}

function validUserId(userId) {
  return Number.isSafeInteger(userId) && userId > 0
}

function storageKey(userId) {
  return `${STORAGE_KEY_PREFIX}${userId}`
}

function safeRemove(key) {
  try {
    localStorage.removeItem(key)
  } catch {
    // Browser privacy settings and storage quotas must not break the form.
  }
}

function sanitizedData(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return null

  const result = {}
  for (const [field, maxLength] of Object.entries(STRING_FIELDS)) {
    if (typeof data[field] !== 'string' || data[field].length > maxLength) return null
    result[field] = data[field]
  }
  if (!Number.isSafeInteger(data.sampleQuantity)
    || data.sampleQuantity < 1) return null
  result.sampleQuantity = data.sampleQuantity
  return result
}

function isEmptyDraft(data, hadFiles) {
  return !hadFiles
    && data.sampleQuantity === 1
    && Object.keys(STRING_FIELDS).every(field => data[field] === '')
}

export function loadApplicationDraft(userId) {
  if (!validUserId(userId)) return null
  const key = storageKey(userId)
  let serialized
  try {
    serialized = localStorage.getItem(key)
  } catch {
    return null
  }
  if (serialized === null) return null

  let payload
  try {
    payload = JSON.parse(serialized)
  } catch {
    safeRemove(key)
    return null
  }

  const savedAt = typeof payload?.savedAt === 'string' ? Date.parse(payload.savedAt) : NaN
  const age = Date.now() - savedAt
  if (payload?.version !== SCHEMA_VERSION
    || !Number.isFinite(savedAt)
    || age < 0
    || age > TTL_MS
    || typeof payload.hadFiles !== 'boolean') {
    safeRemove(key)
    return null
  }

  const data = sanitizedData(payload.data)
  if (!data || isEmptyDraft(data, payload.hadFiles)) {
    safeRemove(key)
    return null
  }
  return { data, hadFiles: payload.hadFiles }
}

export function saveApplicationDraft(userId, data, hadFiles) {
  if (!validUserId(userId)) return
  const sanitized = sanitizedData(data)
  if (!sanitized) return
  if (isEmptyDraft(sanitized, hadFiles === true)) {
    safeRemove(storageKey(userId))
    return
  }
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify({
      version: SCHEMA_VERSION,
      savedAt: new Date().toISOString(),
      data: sanitized,
      hadFiles: hadFiles === true,
    }))
  } catch {
    // Never expose form contents through logs or interrupt user input.
  }
}

export function removeApplicationDraft(userId) {
  if (!validUserId(userId)) return
  safeRemove(storageKey(userId))
}

export const APPLICATION_DRAFT_TTL_MS = TTL_MS
