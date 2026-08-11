// @vitest-environment happy-dom

import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { requestApi } from '../api'
import RequestDetails from './RequestDetails.vue'

vi.mock('../api', () => ({
  requestApi: {
    get: vi.fn(),
    prepareTestAct: vi.fn(),
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
    const app = createApp({
      render: () => h(RequestDetails, { requestId: requestId.value }),
    })
    const root = document.createElement('div')
    document.body.append(root)
    app.mount(root)
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
