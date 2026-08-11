// @vitest-environment happy-dom
import { createApp, nextTick } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import AdminUsers from './AdminUsers.vue'
import { adminApi } from '../api'

vi.mock('../api', () => ({
  adminApi: {
    users: vi.fn(),
    roles: vi.fn(),
    createUser: vi.fn(),
    assignRole: vi.fn(),
    revokeRole: vi.fn(),
  },
}))

let app
let root
afterEach(() => {
  app?.unmount()
  root?.remove()
  app = null
  vi.clearAllMocks()
})

async function mount() {
  root = document.createElement('div')
  document.body.append(root)
  app = createApp(AdminUsers)
  app.mount(root)
  await Promise.resolve()
  await nextTick()
  await Promise.resolve()
  await nextTick()
}

it('renders login and activity states and preserves role assignment', async () => {
  adminApi.users.mockResolvedValue({
    checkedAt: '2026-08-11T12:00:00Z',
    items: [
      { id: 1, displayName: 'Новый пользователь', adLogin: 'new.user', email: null, isActive: true, roles: [], lastLoginAt: null, lastActivityAt: null },
      { id: 2, displayName: 'Активный пользователь', adLogin: 'active.user', email: 'active@example.invalid', isActive: true, roles: [], lastLoginAt: '2026-08-11T11:58:00Z', lastActivityAt: '2026-08-11T11:59:00Z' },
      { id: 3, displayName: 'Давний пользователь', adLogin: 'old.user', email: null, isActive: false, roles: [], lastLoginAt: '2026-08-10T08:00:00Z', lastActivityAt: '2026-08-11T11:45:00Z' },
    ],
  })
  adminApi.roles.mockResolvedValue({ items: [{ id: 4, code: 'expert', name: 'Эксперт' }] })
  adminApi.assignRole.mockResolvedValue({ items: [{ id: 4, code: 'expert', name: 'Эксперт' }] })

  await mount()

  expect(root.textContent).toContain('Вход: не входил')
  expect(root.textContent).toContain('Активен')
  expect(root.textContent).toContain('15 мин назад')
  expect(root.textContent).toContain('Отключена')
  const activeRow = [...root.querySelectorAll('tbody tr')].find(row => row.textContent.includes('Активный пользователь'))
  const roleSelect = activeRow.querySelector('.role-assign select')
  roleSelect.value = '4'
  roleSelect.dispatchEvent(new Event('change'))
  activeRow.querySelector('.role-assign button').click()
  await Promise.resolve()
  await nextTick()
  expect(adminApi.assignRole).toHaveBeenCalledWith(2, 4)
  expect(activeRow.textContent).toContain('Эксперт')
})

it('keeps terminal loading and error states understandable', async () => {
  adminApi.users.mockRejectedValue(new Error('offline'))
  adminApi.roles.mockResolvedValue({ items: [] })
  await mount()
  expect(root.textContent).toContain('Не удалось загрузить список пользователей.')
  expect(root.textContent).not.toContain('Загрузка…')
})
