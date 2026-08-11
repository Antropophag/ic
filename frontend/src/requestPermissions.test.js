import { describe, expect, it } from 'vitest'
import { canCreateRequest } from './requestPermissions'

describe('canCreateRequest', () => {
  it.each(['ic_executor', 'ic_manager', 'laboratory_manager'])(
    'denies request creation and personal registry for %s',
    role => {
      expect(canCreateRequest(['employee', role])).toBe(false)
    },
  )

  it.each([
    { roles: ['employee'] },
    { roles: ['employee', 'expert'] },
    { roles: ['employee', 'security_officer'] },
    { roles: ['employee', 'administrator'] },
  ])('allows request creation and personal registry for $roles', ({ roles }) => {
    expect(canCreateRequest(roles)).toBe(true)
  })
})
