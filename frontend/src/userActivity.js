export const RECENT_ACTIVITY_MS = 10 * 60 * 1000

const absoluteFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})
const timeFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  hour: '2-digit',
  minute: '2-digit',
})
const dayFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: 'numeric',
  month: 'long',
})
const dateKeyFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Moscow',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

export function isRecentlyActive(value, now = Date.now()) {
  if (!value) return false
  const elapsed = now - new Date(value).getTime()
  return elapsed >= 0 && elapsed <= RECENT_ACTIVITY_MS
}

export function relativeActivityTime(value, now = Date.now(), emptyLabel = 'Нет активности', recentLabel = 'Активен') {
  if (!value) return emptyLabel
  const timestamp = new Date(value).getTime()
  const elapsed = now - timestamp
  if (elapsed >= 0 && elapsed <= RECENT_ACTIVITY_MS) return recentLabel
  if (elapsed > 0 && elapsed < 60 * 60 * 1000) return `${Math.max(1, Math.floor(elapsed / 60000))} мин назад`
  if (dateKeyFormatter.format(timestamp) === dateKeyFormatter.format(now)) {
    return `сегодня, ${timeFormatter.format(timestamp)}`
  }
  return `${dayFormatter.format(timestamp)}, ${timeFormatter.format(timestamp)}`
}

export function absoluteActivityTime(value) {
  return value ? absoluteFormatter.format(new Date(value)) : ''
}

export function sortUsersBy(users, order) {
  const copy = [...users]
  if (order === 'lastActivityAt' || order === 'lastLoginAt') {
    return copy.sort((left, right) => {
      const leftTime = left[order] ? new Date(left[order]).getTime() : -Infinity
      const rightTime = right[order] ? new Date(right[order]).getTime() : -Infinity
      return rightTime - leftTime || (left.displayName || left.adLogin).localeCompare(right.displayName || right.adLogin, 'ru')
    })
  }
  return copy.sort((left, right) => (left.displayName || left.adLogin).localeCompare(right.displayName || right.adLogin, 'ru'))
}
