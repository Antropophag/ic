import { afterEach, expect, it, vi } from 'vitest'
import { DEV_USERS, getDevUser, getDevUserId, setDevUserId } from './devUsers'

afterEach(() => {
  vi.unstubAllGlobals()
  setDevUserId(DEV_USERS[0].id)
})

it('lists six seeded users with unique ids', () => {
  expect(DEV_USERS).toHaveLength(6)
  expect(new Set(DEV_USERS.map(user => user.id)).size).toBe(6)
})

it('defaults to the first seeded user without stored preference', () => {
  expect(getDevUserId()).toBe(DEV_USERS[0].id)
  expect(getDevUser()).toEqual(DEV_USERS[0])
})

it('switches the active user and exposes their profile', () => {
  setDevUserId(4)

  expect(getDevUserId()).toBe(4)
  expect(getDevUser()).toEqual(DEV_USERS.find(user => user.id === 4))
})

it('ignores an id outside the seeded list', () => {
  setDevUserId(2)

  setDevUserId(999)

  expect(getDevUserId()).toBe(2)
})

it('persists the selection through localStorage when available', () => {
  const store = new Map()
  vi.stubGlobal('localStorage', {
    getItem: key => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, value),
  })

  setDevUserId(5)

  expect(store.get('shlz-dev-user-id')).toBe('5')
})

it('keeps the switch in memory when localStorage.setItem throws', () => {
  vi.stubGlobal('localStorage', {
    getItem: () => null,
    setItem: () => {
      throw new Error('storage blocked')
    },
  })

  expect(() => setDevUserId(3)).not.toThrow()
  expect(getDevUserId()).toBe(3)
})

it('falls back to the default user when localStorage.getItem throws', () => {
  vi.stubGlobal('localStorage', {
    getItem: () => {
      throw new Error('storage blocked')
    },
    setItem: () => {},
  })

  expect(getDevUserId()).toBe(DEV_USERS[0].id)
})
