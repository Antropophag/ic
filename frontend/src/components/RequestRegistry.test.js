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

describe('RequestRegistry operational summary', () => {
  it('reveals the read-only summary between attention and the registry', async () => {
    requestApi.dashboard.mockResolvedValue({
      operational_summary: {
        active: 4,
        unassigned: 1,
        ready_to_start: 1,
        in_progress: 1,
        suspended: 1,
        expertise: 3,
        security_review: 2,
        directions: [
          { id: 'metrology', title: 'Метрологические испытания', color: 'goldenrod', active: 1, unassigned: 1, executors: [] },
          { id: 'mechanical', title: 'Механические испытания', color: 'blue', active: 2, unassigned: 0, executors: [{ user_id: 12, display_name: 'Кашин', is_available: true, active: 2, in_progress: 1, suspended: 1 }] },
          { id: 'electrical', title: 'Электротехнические испытания', color: 'violet', active: 1, unassigned: 0, executors: [{ user_id: null, display_name: 'Недоступный исполнитель', is_available: false, active: 1 }] },
          { id: 'unclassified', title: 'Без направления', color: 'neutral', active: 0, unassigned: 0, executors: [] },
        ],
      },
      categories: [{ id: 'assign_executor', title: 'Назначить исполнителя', description: 'Назначьте ответственного.', count: 1 }],
    })

    const mounted = mountRegistry(['employee', 'ic_manager'])
    await flushRequests()

    const summary = document.querySelector('.operational-summary')
    const attention = document.querySelector('.attention-dashboard')
    const toolbar = document.querySelector('.registry .toolbar')
    const toggle = [...document.querySelectorAll('button')].find(button => button.textContent.includes('Монитор'))
    expect(summary).not.toBeNull()
    expect(summary.style.display).toBe('none')
    expect(toggle.textContent).toContain('Монитор ИЦ')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    toggle.click()
    await nextTick()
    expect(summary.style.display).not.toBe('none')
    expect(summary.querySelector('h2').textContent).toBe('Монитор ИЦ')
    expect(toggle.getAttribute('aria-expanded')).toBe('true')
    expect(attention.compareDocumentPosition(summary) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
    expect(toggle.compareDocumentPosition(summary) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
    expect(summary.compareDocumentPosition(toolbar) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
    expect(summary.querySelector('.operational-services').textContent).toContain('4В работе ИЦ')
    expect(summary.querySelector('.operational-services').textContent).toContain('2Контроль СБ')
    expect(summary.querySelector('.operational-service--expertise small').textContent).toBe('Подготовказаключения')
    expect(summary.querySelector('.operational-lead-time')).toBeNull()
    expect(summary.querySelectorAll('.operational-service--expertise small span')).toHaveLength(2)
    expect(summary.querySelector('.operational-service--security')).not.toBeNull()
    expect(summary.querySelector('.operational-service--expertise')).not.toBeNull()
    expect(summary.querySelector('.operational-stats')).toBeNull()
    expect(summary.querySelector('.direction-row--goldenrod .direction-people').textContent).toContain('Не назначен1 без исполнителя')
    expect(summary.querySelector('.operational-flow')).toBeNull()
    expect(summary.querySelector('.operational-analytics-note').textContent).toBe('Скоро здесь будет больше аналитических данных.')
    expect(summary.textContent).toContain('Механические испытания')
    expect(summary.textContent).toContain('Метрологические испытания')
    expect(summary.textContent).toContain('Электротехнические испытания')
    expect(summary.textContent).toContain('Без направления')
    expect([...summary.querySelectorAll('.direction-row')].map(row => row.textContent)).toEqual([
      expect.stringContaining('Метрологические'),
      expect.stringContaining('Механические'),
      expect.stringContaining('Электротехнические'),
      expect.stringContaining('Без направления'),
    ])
    const mechanical = summary.querySelector('.direction-row--blue')
    expect(mechanical.textContent).toContain('Кашин')
    expect(mechanical.querySelector('.direction-name').textContent).not.toContain('Исполнители')
    expect(mechanical.querySelector('.direction-people').textContent).toContain('1 в работе · 1 приостановлено')
    const disclosure = mechanical.querySelector('.direction-trigger')
    expect(disclosure.getAttribute('aria-expanded')).toBe('false')
    disclosure.click()
    await nextTick()
    expect(mechanical.classList.contains('direction-row--open')).toBe(true)
    expect(disclosure.getAttribute('aria-expanded')).toBe('true')
    disclosure.focus()
    disclosure.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(mechanical.classList.contains('direction-row--open')).toBe(false)
    expect(document.activeElement).toBe(disclosure)
    disclosure.click()
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await nextTick()
    expect(mechanical.classList.contains('direction-row--open')).toBe(false)
    mounted.roles.value = ['employee']
    await nextTick()
    expect(summary.querySelector('.direction-popover')).toBeNull()
    expect(summary.textContent).not.toContain('Кашин')

    const attentionButton = attention.querySelector('.attention-card')
    attentionButton.click()
    await flushRequests()
    expect(requestApi.list).toHaveBeenLastCalledWith(expect.objectContaining({ attention: 'assign_executor' }))
    mounted.app.unmount()
  })

  it('does not render the summary when backend withholds it', async () => {
    requestApi.dashboard.mockResolvedValue({ operational_summary: null, categories: [] })
    const mounted = mountRegistry(['employee', 'ic_executor'])
    await flushRequests()
    expect(document.querySelector('.operational-summary')).toBeNull()
    expect([...document.querySelectorAll('button')].some(button => button.textContent.includes('Монитор'))).toBe(false)
    mounted.app.unmount()
  })

  it.each([1, 2, 5, 11])('keeps the IC workload label distinct for %i requests', async (active) => {
    requestApi.dashboard.mockResolvedValue({
      operational_summary: {
        active,
        unassigned: 0,
        ready_to_start: 0,
        in_progress: 0,
        suspended: active,
        expertise: 3,
        security_review: 2,
        directions: [{
          id: 'mechanical', title: 'Механические испытания', color: 'blue', active, unassigned: 0,
          executors: [{ user_id: 12, display_name: 'Кашин', is_available: true, active, in_progress: 0, suspended: active }],
        }],
      },
      categories: [],
    })
    const mounted = mountRegistry(['employee', 'ic_manager'])
    await flushRequests()
    const toggle = [...document.querySelectorAll('button')].find(button => button.textContent.includes('Монитор'))
    toggle.click()
    await nextTick()
    const summary = document.querySelector('.operational-summary')
    const direction = summary.querySelector('.direction-row')
    expect(summary.querySelector('.operational-services').textContent).toContain(`${active}В работе ИЦ`)
    expect(summary.querySelector('.operational-services').textContent).toContain('2Контроль СБ')
    expect(summary.querySelector('.operational-service--expertise').textContent).toContain('3Подготовказаключения')
    expect(summary.querySelector('.operational-stats')).toBeNull()
    expect(direction.querySelector('.direction-volume').textContent).toContain(`${active}в работе`)
    expect(direction.querySelector('.direction-people').textContent).toContain(`0 в работе · ${active} приостановлено`)
    expect(direction.querySelector('.direction-volume').textContent).not.toContain('ИЦ')
    expect(direction.querySelector('.direction-people').textContent).not.toContain('ИЦ')
    expect(summary.textContent).not.toMatch(/активн(?:ая|ые|ых) заяв/)
    mounted.app.unmount()
  })
})

describe('RequestRegistry status filter', () => {
  it('filters by multiple semantic status labels and treats no checks as all statuses', async () => {
    const mounted = mountRegistry(['employee'])
    await flushRequests()

    const filter = document.querySelector('.status-filter')
    filter.querySelector('summary').click()
    const labels = [...filter.querySelectorAll('.status-filter-menu label')]
    const rejected = labels.find(label => label.textContent.includes('В проведении испытаний отказано'))
    const completed = labels.find(label => label.textContent.includes('Заявка выполнена'))
    expect(rejected.querySelector('.request-status--rejected')).not.toBeNull()
    rejected.querySelector('input').click()
    completed.querySelector('input').click()
    await flushRequests()

    expect(filter.hasAttribute('open')).toBe(true)
    expect(filter.querySelector('summary').textContent).toBe('Выбрано: 2')
    expect(requestApi.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'completed,rejected' }))

    rejected.querySelector('input').click()
    completed.querySelector('input').click()
    await flushRequests()
    expect(filter.querySelector('summary').textContent).toBe('Статусы')
    expect(requestApi.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: '' }))
    mounted.app.unmount()
  })
})
