import { describe, expect, it } from 'vitest'
import { isRecentlyActive, relativeActivityTime, sortUsersBy } from './userActivity'

describe('user activity presentation', () => {
  const now = new Date('2026-08-11T12:00:00Z').getTime()

  it('distinguishes never logged in, recent, and older activity', () => {
    expect(relativeActivityTime(null, now, 'Не входил')).toBe('Не входил')
    expect(relativeActivityTime('2026-08-11T11:57:00Z', now)).toBe('Активен')
    expect(relativeActivityTime('2026-08-11T11:45:00Z', now)).toBe('15 мин назад')
    expect(isRecentlyActive('2026-08-11T11:50:00Z', now)).toBe(true)
    expect(isRecentlyActive('2026-08-11T11:49:59Z', now)).toBe(false)
  })

  it('does not present a future timestamp as recent activity', () => {
    expect(isRecentlyActive('2026-08-11T12:05:00Z', now)).toBe(false)
    const label = relativeActivityTime('2026-08-11T12:05:00Z', now)
    expect(label).not.toBe('Активен')
    expect(label).toContain('сегодня,')
  })

  it('sorts timestamps newest first and keeps null values last', () => {
    const users = [
      { id: 1, displayName: 'Без входа', lastLoginAt: null },
      { id: 2, displayName: 'Раньше', lastLoginAt: '2026-08-10T08:00:00Z' },
      { id: 3, displayName: 'Позже', lastLoginAt: '2026-08-11T08:00:00Z' },
    ]
    expect(sortUsersBy(users, 'lastLoginAt').map(user => user.id)).toEqual([3, 2, 1])
  })
})
