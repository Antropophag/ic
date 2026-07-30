const STORAGE_KEY = 'shlz-dev-user-id'
const DEFAULT_ID = 1

let overrideId = null

function readStoredId() {
  try {
    if (typeof localStorage === 'undefined') return NaN
    return Number(localStorage.getItem(STORAGE_KEY))
  } catch {
    return NaN
  }
}

function persistId(id) {
  try {
    if (typeof localStorage === 'undefined') return
    localStorage.setItem(STORAGE_KEY, String(id))
  } catch {
    // Персистентность необязательна для dev-переключателя: недоступное
    // хранилище не должно прерывать смену актёра в памяти.
  }
}

export function getDevUserId() {
  if (overrideId !== null) return overrideId
  const stored = readStoredId()
  return Number.isInteger(stored) && stored > 0 ? stored : DEFAULT_ID
}

export function setDevUserId(id) {
  overrideId = id
  persistId(id)
}

// Список dev-аккаунтов резолвится динамически через GET /api/v1/auth/dev-users
// (не хардкодится во фронтенде) — фиксированные id 1-6 не гарантированы на
// давно живущей demo-базе: dev/seed мог отступить на seed-по-ad_login при
// конфликте id (issue про конфликт id в dev/seed, PR #113). Сохранённый или
// дефолтный id мог оказаться недействительным на этой конкретной базе —
// в этом случае переключаемся на первого пользователя из актуального списка.
export function reconcileDevUserId(users) {
  const current = getDevUserId()
  if (users.some(user => user.id === current)) return current
  const fallbackId = users[0]?.id ?? DEFAULT_ID
  setDevUserId(fallbackId)
  return fallbackId
}
