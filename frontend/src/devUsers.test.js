import { afterEach, expect, it, vi } from 'vitest'
import { DEV_USERS, getDevUser, getDevUserId, setDevUserId } from './devUsers'

afterEach(() => {
  vi.unstubAllGlobals()
  setDevUserId(DEV_USERS[0].id)
})

it('lists five seeded users with unique ids', () => {
  expect(DEV_USERS).toHaveLength(5)
  expect(new Set(DEV_USERS.map(user => user.id)).size).toBe(5)
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
