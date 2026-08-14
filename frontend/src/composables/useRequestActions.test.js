// @vitest-environment happy-dom

import { createApp, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { requestApi } from '../api'
import { useRequestActions } from './useRequestActions'

vi.mock('../api', () => ({
  requestApi: {
    assignExecutor: vi.fn(),
    changeDepartment: vi.fn(),
    claimExpert: vi.fn(),
    decideSecurity: vi.fn(),
    executors: vi.fn(),
    experts: vi.fn(),
    publishOpinion: vi.fn(),
    reassignExpert: vi.fn(),
    reject: vi.fn(),
    resume: vi.fn(),
    setColor: vi.fn(),
    start: vi.fn(),
    suspend: vi.fn(),
    withdraw: vi.fn(),
  },
}))

function currentRequest(overrides = {}) {
  return ref({
    backendId: 1,
    lockVersion: 4,
    executorId: 7,
    department: 'ИЦ',
    color: 'none',
    ...overrides,
  })
}

function createActions(overrides = {}) {
  const request = currentRequest(overrides)
  const refresh = vi.fn().mockResolvedValue(undefined)
  let actions
  const root = document.createElement('div')
  const app = createApp({
    setup() {
      actions = useRequestActions(request, refresh)
      return () => null
    },
  })
  app.mount(root)
  return { actions, refresh, request, stop: () => app.unmount() }
}

async function confirm(actions, operation) {
  const result = operation()
  await nextTick()
  actions.confirmDialog.state.reasonValue = 'Причина'
  actions.confirmDialog.accept()
  await result
}

afterEach(() => vi.resetAllMocks())

describe('useRequestActions workflows', () => {
  it('runs the assign, lifecycle, expert, decision, opinion, color, and department mutations through parent refresh', async () => {
    Object.values(requestApi).forEach(mock => mock.mockResolvedValue?.({ items: [] }))
    const { actions, refresh, stop } = createActions()

    actions.executorChoice.value = '8'
    await confirm(actions, () => actions.assignExecutor())
    await confirm(actions, () => actions.startRequest())
    await confirm(actions, () => actions.suspendOrResumeRequest('suspend'))
    await confirm(actions, () => actions.suspendOrResumeRequest('resume'))
    await actions.claimExpert()
    actions.expertChoice.value = '9'
    await confirm(actions, () => actions.reassignExpert())
    await confirm(actions, () => actions.rejectRequest())
    await confirm(actions, () => actions.withdrawRequest())
    await confirm(actions, () => actions.decideSecurity('approve'))
    await confirm(actions, () => actions.decideSecurity('return'))
    actions.opinionDraft.value = 'Подробное экспертное заключение'
    await actions.publishOpinion()
    await actions.setColorMark('red')
    actions.departmentDraft.value = 'Новый отдел'
    await actions.changeDepartment()

    expect(requestApi.assignExecutor).toHaveBeenCalledWith(1, 8, 4)
    expect(requestApi.suspend).toHaveBeenCalledWith(1, 4, 'Причина')
    expect(requestApi.resume).toHaveBeenCalledWith(1, 4)
    expect(requestApi.reassignExpert).toHaveBeenCalledWith(1, 9, 4)
    expect(requestApi.decideSecurity).toHaveBeenCalledWith(1, 'approve', null, 4)
    expect(requestApi.decideSecurity).toHaveBeenCalledWith(1, 'return', 'Причина', 4)
    expect(requestApi.publishOpinion).toHaveBeenCalledWith(1, 'Подробное экспертное заключение', 4)
    expect(requestApi.changeDepartment).toHaveBeenCalledWith(1, 'Новый отдел', 4)
    expect(refresh).toHaveBeenCalledTimes(13)
    stop()
  })

  it('loads capability lists and keeps operation-specific validation errors separate', async () => {
    requestApi.executors.mockResolvedValue({ items: [{ id: 8 }] })
    requestApi.experts.mockResolvedValue({ items: [{ id: 9 }] })
    const { actions, stop } = createActions({ canAssignExecutor: true, canReassignExpert: true })
    await nextTick()
    await Promise.resolve()

    expect(actions.executors.value).toEqual([{ id: 8 }])
    expect(actions.experts.value).toEqual([{ id: 9 }])
    actions.executorChoice.value = ''
    await actions.assignExecutor()
    await actions.reassignExpert()
    actions.opinionDraft.value = 'коротко'
    await actions.publishOpinion()

    expect(actions.actionError.value).toBe('Выберите исполнителя.')
    expect(actions.reassignError.value).toBe('Выберите эксперта.')
    expect(actions.opinionError.value).toBe('Заключение должно содержать не менее 10 символов.')
    stop()
  })

  it('maps workflow failures without refreshing stale state', async () => {
    requestApi.suspend.mockRejectedValue({ status: 403 })
    requestApi.decideSecurity.mockRejectedValue({ status: 422 })
    requestApi.publishOpinion.mockRejectedValue({ status: 403 })
    const { actions, refresh, stop } = createActions()

    await confirm(actions, () => actions.suspendOrResumeRequest('suspend'))
    await confirm(actions, () => actions.decideSecurity('return'))
    actions.opinionDraft.value = 'Подробное экспертное заключение'
    await actions.publishOpinion()

    expect(actions.suspendResumeError.value).toContain('назначенный исполнитель')
    expect(actions.securityError.value).toBe('Проверьте решение и причину возврата.')
    expect(actions.opinionError.value).toContain('назначенный эксперт')
    expect(refresh).not.toHaveBeenCalled()
    stop()
  })
})
