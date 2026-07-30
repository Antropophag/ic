import { afterEach, expect, it, vi } from 'vitest'
import { getDevUserId, reconcileDevUserId, setDevUserId } from './devUsers'

const SEEDED_USERS = [
  { id: 1, displayName: 'Максим Умнов', position: 'Руководитель ИЦ' },
  { id: 2, displayName: 'Сергей Кашин', position: 'Исполнитель ИЦ' },
  { id: 4, displayName: 'Анна Смирнова', position: 'Эксперт' },
]

afterEach(() => {
  vi.unstubAllGlobals()
  setDevUserId(1)
})

it('defaults to id 1 without a stored preference', () => {
  expect(getDevUserId()).toBe(1)
})

it('switches the active user id', () => {
  setDevUserId(4)

  expect(getDevUserId()).toBe(4)
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

it('falls back to the default id when localStorage.getItem throws', () => {
  vi.stubGlobal('localStorage', {
    getItem: () => {
      throw new Error('storage blocked')
    },
    setItem: () => {},
  })

  expect(getDevUserId()).toBe(1)
})

it('reconciles to the current id when it is present in the fetched list', () => {
  setDevUserId(2)

  expect(reconcileDevUserId(SEEDED_USERS)).toBe(2)
  expect(getDevUserId()).toBe(2)
})

it('reconciles to the first fetched user when the current id is unknown on this database', () => {
  setDevUserId(999)
  const usersOnConflictedDatabase = [{ id: 7, displayName: 'Дарья Королёва', position: 'Администратор портала' }]

  expect(reconcileDevUserId(usersOnConflictedDatabase)).toBe(7)
  expect(getDevUserId()).toBe(7)
})
