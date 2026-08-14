// @vitest-environment happy-dom
/* eslint-disable vue/one-component-per-file */

import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { requestApi } from '../api'
import RequestDetails from './RequestDetails.vue'

vi.mock('../api', () => ({
  requestApi: {
    addComment: vi.fn(),
    comments: vi.fn(),
    downloadDocument: vi.fn(),
    get: vi.fn(),
    prepareTestAct: vi.fn(),
    start: vi.fn(),
    uploadDocument: vi.fn(),
  },
}))

function deferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, reject, resolve }
}

function requestDetails(id, overrides = {}) {
  return {
    item: {
      id,
      number: id,
      created_at: '2026-08-11T10:00:00Z',
      initiator_name: 'Инициатор',
      department: 'Испытательный центр',
      product_name: `Образец ${id}`,
      manufacturer: 'Производитель',
      supplier: 'Поставщик',
      sample_quantity: 1,
      test_method: 'Метод испытаний',
      executor_name: 'Исполнитель',
      executor_id: 7,
      status: 'in_progress',
      lockVersion: 1,
      ...overrides,
    },
    history: [],
    comments: [],
    commentsPage: { hasMore: false },
    documents: [],
  }
}

async function flushRequests() {
  await Promise.resolve()
  await nextTick()
  await Promise.resolve()
  await nextTick()
}

function mountDetails(props = {}) {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp({ render: () => h(RequestDetails, { requestId: 1, ...props }) })
  app.mount(root)
  return { app, root }
}

function button(label) {
  return [...document.querySelectorAll('button')].find(candidate => candidate.textContent.trim() === label)
}

afterEach(() => {
  vi.resetAllMocks()
  document.body.replaceChildren()
})

describe('RequestDetails characterization', () => {
  it('loads and presents current request details through the loaded contract', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1))
    const loaded = vi.fn()
    const { app } = mountDetails({ onLoaded: loaded })
    await flushRequests()

    expect(requestApi.get).toHaveBeenCalledWith(1)
    expect(document.querySelector('.object-title').textContent).toBe('Образец 1')
    expect(loaded).toHaveBeenCalledWith(expect.objectContaining({ backendId: 1, product: 'Образец 1' }))
    app.unmount()
  })

  it('does not let a late details response overwrite a newer request', async () => {
    const first = deferred()
    const second = deferred()
    const requestId = ref(1)
    requestApi.get.mockImplementation(id => id === 1 ? first.promise : second.promise)
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp({ render: () => h(RequestDetails, { requestId: requestId.value }) })
    app.mount(root)

    requestId.value = 2
    await nextTick()
    second.resolve(requestDetails(2))
    await flushRequests()
    first.resolve(requestDetails(1))
    await flushRequests()

    expect(document.querySelector('.object-title').textContent).toBe('Образец 2')
    app.unmount()
  })

  it('shows key action groups only when backend capabilities allow them', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1, {
      status: 'registered',
      can_assign_executor: 1,
      can_start: 1,
      can_claim_expert: 1,
      can_security_decide: 1,
      can_reject: 1,
      can_withdraw: 1,
      can_upload_document: 1,
      can_upload_report: 1,
    }))
    requestApi.executors = vi.fn().mockResolvedValue({ items: [] })
    const { app } = mountDetails()
    await flushRequests()

    expect(document.querySelector('[aria-label="Исполнитель ИЦ"]')).not.toBeNull()
    expect(button('Начать работу')).not.toBeNull()
    expect(button('Взять в работу')).not.toBeNull()
    expect(button('Согласовать')).not.toBeNull()
    expect(button('Отказать')).not.toBeNull()
    expect(button('Отозвать')).not.toBeNull()
    expect(document.querySelector('.request-document-upload')).not.toBeNull()
    expect(document.body.textContent).toContain('Загрузить отчёт')
    app.unmount()
  })

  it('hides action, comment, document, color, and department controls without backend capabilities', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1))
    const { app } = mountDetails()
    await flushRequests()

    expect(document.querySelector('.request-process-action')).toBeNull()
    expect(document.querySelector('.request-comment-composer')).toBeNull()
    expect(document.querySelector('.request-document-upload')).toBeNull()
    expect(document.querySelector('.request-color-control')).toBeNull()
    expect(button('Изменить')).toBeUndefined()
    expect(button('Загрузить отчёт')).toBeUndefined()
    app.unmount()
  })

  it('presents the remaining capability-driven workflow controls', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1, {
      status: 'suspended',
      can_resume: 1,
      can_comment: 1,
      can_delete_report: 1,
      can_publish_opinion: 1,
      can_reassign_expert: 1,
      can_set_color: 1,
      can_edit_department: 1,
    }))
    requestApi.experts = vi.fn().mockResolvedValue({ items: [] })
    const { app } = mountDetails()
    await flushRequests()

    expect(button('Возобновить')).not.toBeUndefined()
    expect(document.querySelector('.request-comment-composer')).not.toBeNull()
    expect(button('Удалить отчёт')).not.toBeUndefined()
    expect(button('Написать заключение')).not.toBeUndefined()
    expect(document.querySelector('[aria-label="Новый эксперт"]')).not.toBeNull()
    expect(document.querySelector('.request-color-control')).not.toBeNull()
    expect(button('Изменить')).not.toBeUndefined()
    app.unmount()
  })

  it('shows suspend only for the corresponding capability', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1, { can_suspend: 1 }))
    const { app } = mountDetails()
    await flushRequests()

    expect(button('Приостановить')).not.toBeUndefined()
    expect(button('Возобновить')).toBeUndefined()
    app.unmount()
  })

  it('performs a typical mutation and refreshes through the details loader', async () => {
    requestApi.get
      .mockResolvedValueOnce(requestDetails(1, { status: 'registered', can_start: 1 }))
      .mockResolvedValueOnce(requestDetails(1, { status: 'in_progress', can_start: 0, lockVersion: 2 }))
    requestApi.start.mockResolvedValue({})
    const updated = vi.fn()
    const { app } = mountDetails({ onUpdated: updated })
    await flushRequests()

    button('Начать работу').click()
    await nextTick()
    document.querySelector('.modal-actions .primary').click()
    await flushRequests()

    expect(requestApi.start).toHaveBeenCalledWith(1, 1)
    expect(requestApi.get).toHaveBeenCalledTimes(2)
    expect(document.body.textContent).toContain('Заявка в работе')
    app.unmount()
  })

  it('recovers from an optimistic-lock conflict by refreshing current details', async () => {
    requestApi.get
      .mockResolvedValueOnce(requestDetails(1, { status: 'registered', can_start: 1 }))
      .mockResolvedValueOnce(requestDetails(1, { status: 'in_progress', can_start: 0, lockVersion: 2 }))
    requestApi.start.mockRejectedValue({ status: 409 })
    const { app } = mountDetails()
    await flushRequests()

    button('Начать работу').click()
    await nextTick()
    document.querySelector('.modal-actions .primary').click()
    await flushRequests()

    expect(requestApi.get).toHaveBeenCalledTimes(2)
    await vi.waitFor(() => {
      expect(document.body.textContent).toContain('Заявка уже изменена. Данные обновлены — проверьте актуальный статус.')
    })
    app.unmount()
  })

  it('adds a comment and prepends older comments without reloading details', async () => {
    requestApi.get.mockResolvedValue({
      ...requestDetails(1, { can_comment: 1 }),
      comments: [{ id: 2, author_name: 'Новый автор', body: 'Текущий комментарий', created_at: '2026-08-11T11:00:00Z' }],
      commentsPage: { hasMore: true, nextBeforeId: 2 },
    })
    requestApi.addComment.mockResolvedValue({ id: 3, author_name: 'Я', body: 'Добавленный комментарий', created_at: '2026-08-11T12:00:00Z' })
    requestApi.comments.mockResolvedValue({ items: [{ id: 1, author_name: 'Старый автор', body: 'Старый комментарий', created_at: '2026-08-10T12:00:00Z' }], hasMore: false, nextBeforeId: null })
    const { app } = mountDetails({ currentInitials: 'Я' })
    await flushRequests()

    const input = document.querySelector('.request-comment-composer input')
    input.value = 'Добавленный комментарий'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    document.querySelector('.request-comment-composer').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flushRequests()
    button('Показать ранние комментарии').click()
    await flushRequests()

    expect(requestApi.addComment).toHaveBeenCalledWith(1, 'Добавленный комментарий')
    expect(requestApi.comments).toHaveBeenCalledWith(1, 2)
    expect(document.body.textContent).toContain('Добавленный комментарий')
    expect(document.body.textContent).toContain('Старый комментарий')
    expect(requestApi.get).toHaveBeenCalledTimes(1)
    app.unmount()
  })

  it('uploads a document and refreshes the canonical details', async () => {
    requestApi.get
      .mockResolvedValueOnce(requestDetails(1, { can_upload_document: 1 }))
      .mockResolvedValueOnce({ ...requestDetails(1), documents: [{ id: 5, version_id: 9, title: 'Протокол.pdf', original_name: 'Протокол.pdf', mime_type: 'application/pdf', size: 10, version: 1, created_at: '2026-08-11T12:00:00Z' }] })
    requestApi.uploadDocument.mockResolvedValue({})
    const { app } = mountDetails()
    await flushRequests()

    const input = document.querySelector('.request-document-upload input')
    const file = new File(['document'], 'Протокол.pdf', { type: 'application/pdf' })
    Object.defineProperty(input, 'files', { configurable: true, value: [file] })
    input.dispatchEvent(new Event('change', { bubbles: true }))
    await flushRequests()

    expect(requestApi.uploadDocument).toHaveBeenCalledWith(1, file)
    expect(requestApi.get).toHaveBeenCalledTimes(2)
    expect(document.body.textContent).toContain('Протокол.pdf')
    app.unmount()
  })

  it('opens and closes the audit overlay while restoring focus to its trigger', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1))
    const { app } = mountDetails()
    await flushRequests()

    const trigger = button('Подробная история')
    trigger.focus()
    trigger.click()
    await nextTick()
    expect(document.querySelector('[aria-labelledby="audit-title"]')).not.toBeNull()
    expect(document.activeElement.getAttribute('aria-label')).toBe('Закрыть историю')

    document.activeElement.click()
    await nextTick()
    expect(document.querySelector('[aria-labelledby="audit-title"]')).toBeNull()
    expect(document.activeElement).toBe(trigger)
    app.unmount()
  })
})

describe('RequestDetails test-act draft lifecycle', () => {
  it('ignores a late preparation response after switching the request', async () => {
    const preparation = deferred()
    const requestId = ref(1)
    requestApi.get.mockImplementation(id => Promise.resolve(requestDetails(id, { can_upload_report: 1 })))
    requestApi.prepareTestAct.mockReturnValue(preparation.promise)
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp({ render: () => h(RequestDetails, { requestId: requestId.value }) })
    app.mount(root)
    await flushRequests()

    document.querySelector('[aria-label="Сформировать шаблон отчётного документа"]').click()
    await nextTick()
    expect(requestApi.prepareTestAct).toHaveBeenCalledWith(1)

    requestId.value = 2
    await flushRequests()
    preparation.resolve({ documentType: 'test_act', actNumber: '1', actDate: '11.08.2026', basis: 'Заявка № 1', result: '', sampleName: 'Устаревший образец', testMethod: 'Устаревший метод', requestNumber: 1 })
    await flushRequests()

    expect(document.querySelector('#test-act-modal-title')).toBeNull()
    expect(document.body.textContent).not.toContain('Устаревший образец')
    app.unmount()
  })
})
