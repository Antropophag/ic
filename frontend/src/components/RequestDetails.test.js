// @vitest-environment happy-dom

import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { requestApi } from '../api'
import RequestDetails from './RequestDetails.vue'

vi.mock('../api', () => ({
  requestApi: {
    get: vi.fn(),
    prepareTestAct: vi.fn(),
    setColor: vi.fn(),
  },
}))

function deferred() {
  let resolve
  const promise = new Promise(resolvePromise => { resolve = resolvePromise })
  return { promise, resolve }
}

function requestDetails(id, productName) {
  return {
    item: {
      id,
      number: id,
      created_at: '2026-08-11T10:00:00Z',
      initiator_name: 'Инициатор',
      department: 'Испытательный центр',
      product_name: productName,
      manufacturer: 'Производитель',
      supplier: 'Поставщик',
      sample_quantity: 1,
      test_method: 'Метод испытаний',
      executor_name: 'Исполнитель',
      executor_id: 7,
      status: 'in_progress',
      lockVersion: 1,
      can_upload_report: 1,
    },
    history: [],
    comments: [],
    commentsPage: { hasMore: false },
    documents: [],
  }
}

function mountDetails(requestId) {
  const app = createApp({ render: () => h(RequestDetails, { requestId: requestId.value }) })
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  return { app, root }
}

async function flushRequests() {
  await Promise.resolve()
  await nextTick()
  await Promise.resolve()
  await nextTick()
}

afterEach(() => {
  vi.clearAllMocks()
  document.body.replaceChildren()
})

describe('RequestDetails test-act draft lifecycle', () => {
  it('ignores a late preparation response after switching the request', async () => {
    const preparation = deferred()
    const requestId = ref(1)
    requestApi.get.mockImplementation(id => Promise.resolve(requestDetails(id, `Образец ${id}`)))
    requestApi.prepareTestAct.mockReturnValue(preparation.promise)
    const { app } = mountDetails(requestId)
    await flushRequests()

    document.querySelector('[aria-label="Сформировать шаблон отчётного документа"]').click()
    await nextTick()
    expect(requestApi.prepareTestAct).toHaveBeenCalledWith(1)

    requestId.value = 2
    await flushRequests()
    preparation.resolve({
      documentType: 'test_act',
      actNumber: '1',
      actDate: '11.08.2026',
      basis: 'Заявка № 1',
      result: '',
      sampleName: 'Устаревший образец',
      testMethod: 'Устаревший метод',
      requestNumber: 1,
    })
    await flushRequests()

    expect(document.querySelector('#test-act-modal-title')).toBeNull()
    expect(document.body.textContent).not.toContain('Устаревший образец')
    expect(document.querySelector('[aria-label="Сформировать шаблон отчётного документа"]').disabled).toBe(false)
    app.unmount()
  })
})

describe('RequestDetails testing direction', () => {
  it('names directions and saves the existing color code and version', async () => {
    const response = requestDetails(1, 'Образец')
    Object.assign(response.item, { color: 'blue', can_set_color: 1 })
    requestApi.get.mockResolvedValue(response)
    requestApi.setColor.mockImplementation(async (_id, color) => { response.item.color = color })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()

    const menu = root.querySelector('[aria-label="Направление испытаний"]')
    expect(menu).not.toBeNull()
    const options = [...menu.querySelectorAll('button')]
    expect(options.map(button => button.textContent)).toEqual([
      'Без направления', 'Метрологические испытания', 'Механические испытания',
      'Электротехнические испытания', 'Резерв 1', 'Резерв 2',
    ])
    expect(root.querySelector('.request-color-control summary').textContent).toContain('Механические испытания')
    options[1].click()
    await flushRequests()
    expect(requestApi.setColor).toHaveBeenCalledWith(1, 'orange', 1)
    expect(root.querySelector('.request-color-control summary').textContent).toContain('Метрологические испытания')
    app.unmount()
  })

  it('shows the direction without editing controls when the user lacks permission', async () => {
    const response = requestDetails(1, 'Образец')
    Object.assign(response.item, { color: 'green', can_set_color: 0 })
    requestApi.get.mockResolvedValue(response)
    const { app, root } = mountDetails(ref(1))
    await flushRequests()

    expect(root.querySelector('.request-direction-readonly').textContent).toContain('Резерв 2')
    expect(root.querySelector('.request-color-control')).toBeNull()
    const status = root.querySelector('.shlz-status--neutral')
    expect(status.textContent).toBe('Заявка в работе')
    expect(status.querySelector('.shlz-badge-dot').getAttribute('aria-hidden')).toBe('true')
    app.unmount()
  })
})
