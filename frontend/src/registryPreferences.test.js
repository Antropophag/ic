import { describe, expect, it, vi } from 'vitest'
import { DEFAULT_REGISTRY_PAGE_SIZE, notificationCursorKey, readNotificationCursor, readRegistryPageSize, writeNotificationCursor, writeRegistryPageSize } from './registryPreferences'

function storage(value = null) {
  return { getItem: () => value, setItem: vi.fn() }
}

describe('registry preferences', () => {
  it.each([20, 50, 100])('restores supported page size %i', size => {
    expect(readRegistryPageSize(storage(String(size)))).toBe(size)
  })

  it.each([null, '', '10', '25', 'broken', '0'])('safely replaces unsupported page size %s', value => {
    expect(readRegistryPageSize(storage(value))).toBe(DEFAULT_REGISTRY_PAGE_SIZE)
  })

  it('stores only supported page sizes', () => {
    const target = storage()
    expect(writeRegistryPageSize(50, target)).toBe(true)
    expect(target.setItem).toHaveBeenCalledWith('ic.requestRegistry.pageSize.v1', '50')
    expect(writeRegistryPageSize(25, target)).toBe(false)
  })

  it('scopes a valid notification cursor to the current user', () => {
    const target = storage('2026-08-06T10:00:00.000000Z')
    expect(notificationCursorKey(17)).toBe('ic.requestRegistry.notifications.17.v1')
    expect(readNotificationCursor(17, target)).toBe('2026-08-06T10:00:00.000000Z')
    expect(writeNotificationCursor(17, 'broken', target)).toBe(false)
  })
})
