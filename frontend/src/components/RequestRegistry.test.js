// @vitest-environment happy-dom

import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { requestApi } from '../api'
import RequestRegistry from './RequestRegistry.vue'

vi.mock('../api', () => ({
  requestApi: {
    addComment: vi.fn(),
    create: vi.fn(),
    dashboard: vi.fn(),
    downloadDocument: vi.fn(),
    events: vi.fn(),
    list: vi.fn(),
    uploadDocument: vi.fn(),
  },
}))

function deferred() {
  let resolve
  const promise = new Promise(resolvePromise => { resolve = resolvePromise })
  return { promise, resolve }
}

function mountRegistry(initialRoles) {
  const roles = ref(initialRoles)
  const registry = ref(null)
  const selectedRequests = []
  const app = createApp({
    render: () => h(RequestRegistry, {
      ref: registry,
      active: true,
      currentUserId: 7,
      currentUserRoles: roles.value,
      onSelectRequest: item => selectedRequests.push(item),
    }),
  })
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  return { app, registry, roles, selectedRequests }
}

async function flushRequests() {
  await Promise.resolve()
  await nextTick()
  await Promise.resolve()
}

beforeEach(() => {
  requestApi.list.mockResolvedValue({
    items: [], total: 0, page: 1, pageSize: 10, pageCount: 1,
    counts: { active: 0, all: 0, mine: 0 },
  })
  requestApi.dashboard.mockResolvedValue({ categories: [] })
  requestApi.events.mockResolvedValue({ items: [] })
})

afterEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  document.body.replaceChildren()
})

describe('RequestRegistry request creation permissions', () => {
  it('opens the creation form only for an allowed role', async () => {
    const allowed = mountRegistry(['employee'])
    await flushRequests()
    allowed.registry.value.openCreate()
    await nextTick()
    expect(document.querySelector('#create-request-title')).not.toBeNull()
    allowed.app.unmount()

    const denied = mountRegistry(['employee', 'ic_executor'])
    await flushRequests()
    denied.registry.value.openCreate()
    await nextTick()
    expect(document.querySelector('#create-request-title')).toBeNull()
    denied.app.unmount()
  })

  it('ignores a late create response after the user loses permission', async () => {
    const createResult = deferred()
    requestApi.create.mockReturnValue(createResult.promise)
    const mounted = mountRegistry(['employee'])
    await flushRequests()
    const initialListCalls = requestApi.list.mock.calls.length

    mounted.registry.value.openCreate()
    await nextTick()
    document.querySelector('form.modal').dispatchEvent(new Event('submit', {
      bubbles: true,
      cancelable: true,
    }))
    await nextTick()
    expect(requestApi.create).toHaveBeenCalledOnce()

    mounted.roles.value = ['employee', 'ic_manager']
    await nextTick()
    expect(document.querySelector('#create-request-title')).toBeNull()

    createResult.resolve({ id: 101 })
    await flushRequests()
    expect(requestApi.list).toHaveBeenCalledTimes(initialListCalls)
    expect(requestApi.uploadDocument).not.toHaveBeenCalled()
    expect(requestApi.addComment).not.toHaveBeenCalled()
    expect(mounted.selectedRequests).toEqual([])
    mounted.app.unmount()
  })
})
