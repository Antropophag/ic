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
    executors: vi.fn().mockResolvedValue({ items: [] }),
    chooseRoute: vi.fn(),
    completeAct: vi.fn(),
    decideSecurity: vi.fn(),
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
  it('lets a manager explicitly replace an unknown legacy mark with no direction', async () => {
    const response = requestDetails(1, 'Образец')
    Object.assign(response.item, { color: 'yellow', can_set_color: 1 })
    requestApi.get.mockResolvedValue(response)
    requestApi.setColor.mockImplementation(async (_id, color) => { response.item.color = color })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    expect(root.querySelector('.request-color-control summary').textContent).toContain('Направление не определено')
    const menu = root.querySelector('[aria-label="Направление испытаний"]')
    expect(menu.querySelector('[aria-pressed="true"]')).toBeNull()
    menu.querySelector('button').click()
    await flushRequests()
    expect(requestApi.setColor).toHaveBeenCalledWith(1, 'white', 1)
    expect(root.querySelector('.request-color-control summary').textContent).toContain('Без направления')
    app.unmount()
  })

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
    expect(status.querySelector('.shlz-badge-dot')).toBeNull()
    app.unmount()
  })
})

describe('RequestDetails document metadata', () => {
  it('shows the uploader and Moscow upload time, and labels unknown legacy metadata', async () => {
    const details = requestDetails(1, 'Образец')
    details.documents = [
      { id: 1, versionId: 1, version: 2, title: 'Новый.pdf', sizeBytes: 1024, uploadedBy: 'Автор загрузки', createdAt: '2026-07-28T23:30:00Z' },
      { id: 2, versionId: 2, version: 1, title: 'Архив.pdf', sizeBytes: 1024, uploadedBy: null, createdAt: null },
    ]
    requestApi.get.mockResolvedValue(details)
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    const cards = root.querySelectorAll('.request-file-copy')
    expect(cards[0].textContent).toContain('Автор загрузки')
    expect(cards[0].textContent).toContain('29.07.2026, 02:30:00')
    expect(cards[1].textContent).toContain('Автор неизвестен')
    expect(cards[1].textContent).toContain('Дата загрузки неизвестна')
    app.unmount()
  })
})


describe('RequestDetails route and security decisions', () => {
  const routed = (id, extra = {}) => {
    const details = requestDetails(id, `Образец ${id}`)
    Object.assign(details.item, { route: 'act', can_choose_route: 1, ...extra })
    return details
  }
  const button = label => [...document.querySelectorAll('button')].find(item => item.textContent.trim() === label)
  async function selectProtocol() {
    if (!document.querySelector('#request-route')) {
      button('Изменить маршрут').click()
      await nextTick()
    }
    const select = document.querySelector('#request-route')
    select.value = 'protocol'
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    button('Сохранить маршрут').click()
    await flushRequests()
  }

  it('saves the selected route with the current version and refreshes its timeline', async () => {
    requestApi.get.mockResolvedValueOnce(routed(1)).mockResolvedValueOnce(routed(1, { route: 'protocol', lockVersion: 2 }))
    requestApi.chooseRoute.mockResolvedValue({ lockVersion: 2 })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    expect(root.querySelector('.request-process-action')).not.toBeNull()
    expect(root.querySelector('.request-route')).toBeNull()
    expect(root.querySelectorAll('.process-timeline li')).toHaveLength(3)
    await selectProtocol()
    expect(requestApi.chooseRoute).toHaveBeenCalledWith(1, 'protocol', 1)
    expect(root.querySelectorAll('.process-timeline li')).toHaveLength(5)
    app.unmount()
  })

  it('shows route selection before executor assignment without changing the registered status or timeline', async () => {
    requestApi.get.mockResolvedValueOnce(routed(1, { route: null, status: 'registered', can_assign_executor: 0, can_upload_report: 0 }))
      .mockResolvedValueOnce(routed(1, { route: 'protocol', status: 'registered', can_assign_executor: 1, can_upload_report: 0, lockVersion: 2 }))
    requestApi.chooseRoute.mockResolvedValue({ lockVersion: 2 })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    const before = root.querySelector('.process-timeline').textContent
    expect(root.querySelector('.request-process-action #request-route')).not.toBeNull()
    expect(root.querySelector('select[aria-label="Исполнитель ИЦ"]')).toBeNull()
    await selectProtocol()
    expect(root.querySelector('#request-route')).toBeNull()
    expect(root.querySelector('select[aria-label="Исполнитель ИЦ"]')).not.toBeNull()
    expect(root.querySelector('.process-timeline').textContent).toBe(before)
    expect(root.textContent).toContain('Заявка зарегистрирована')
    expect(button('Изменить маршрут')).toBeDefined()
    app.unmount()
  })

  it.each([403, 500])('shows a route failure (%i) without reporting success', async status => {
    requestApi.get.mockResolvedValue(routed(1))
    requestApi.chooseRoute.mockRejectedValue(Object.assign(new Error('Rejected'), { status }))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    await selectProtocol()
    expect(root.textContent).toContain(status === 403 ? 'Действие недоступно' : 'Не удалось обновить заявку')
    expect(button('Сохранить маршрут').disabled).toBe(false)
    app.unmount()
  })

  it('refreshes the route after a version conflict', async () => {
    requestApi.get.mockResolvedValueOnce(routed(1)).mockResolvedValueOnce(routed(1, { route: 'protocol', lockVersion: 2 }))
    requestApi.chooseRoute.mockRejectedValue(Object.assign(new Error('Conflict'), { status: 409 }))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    await selectProtocol()
    expect(requestApi.get).toHaveBeenCalledTimes(2)
    expect(root.textContent).toContain('Заявка уже изменена')
    expect(root.querySelector('#request-route').value).toBe('protocol')
    app.unmount()
  })

  it('does not complete an act when its confirmation is cancelled', async () => {
    requestApi.get.mockResolvedValue(routed(1, { can_choose_route: 0, can_complete_act: 1 }))
    const { app } = mountDetails(ref(1))
    await flushRequests()
    button('Завершить заявку').click()
    await nextTick()
    button('Отмена').click()
    await flushRequests()
    expect(requestApi.completeAct).not.toHaveBeenCalled()
    app.unmount()
  })

  it.each([403, 422, 500])('keeps the security decision available when saving fails (%i)', async status => {
    requestApi.get.mockResolvedValue(routed(1, { route: 'protocol', status: 'security_review', can_choose_route: 0, can_security_decide: 1 }))
    requestApi.decideSecurity.mockRejectedValue(Object.assign(new Error('Rejected'), { status }))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    button('Не согласовано').click()
    await nextTick()
    ;[...document.querySelector('[role="alertdialog"]').querySelectorAll('button')].find(item => item.textContent.trim() === 'Не согласовано').click()
    await flushRequests()
    expect(root.textContent).toContain(status === 422 ? 'Проверьте выбранное решение' : status === 403 ? 'Решение может принять только сотрудник СБ' : 'Не удалось сохранить решение СБ')
    expect(button('Не согласовано').disabled).toBe(false)
    app.unmount()
  })

  it('ignores a route response after closing the card', async () => {
    const pending = deferred()
    requestApi.get.mockResolvedValue(routed(1))
    requestApi.chooseRoute.mockReturnValue(pending.promise)
    const { app } = mountDetails(ref(1))
    await flushRequests()
    await selectProtocol()
    app.unmount()
    pending.resolve({ lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(1)
  })

  it('ignores a route response arriving after another card has been updated', async () => {
    const pending = deferred()
    const id = ref(1)
    requestApi.get.mockImplementation(value => Promise.resolve(routed(value)))
    requestApi.chooseRoute.mockReturnValueOnce(pending.promise).mockResolvedValueOnce({ lockVersion: 2 })
    const { app, root } = mountDetails(id)
    await flushRequests()
    await selectProtocol()
    id.value = 2
    await flushRequests()
    await selectProtocol()
    const calls = requestApi.get.mock.calls.length
    pending.resolve({ lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(calls)
    expect(root.textContent).toContain('Образец 2')
    app.unmount()
  })

  it('requires an explicit confirmation to complete an act', async () => {
    requestApi.get.mockResolvedValue(routed(1, { can_choose_route: 0, can_complete_act: 1, can_assign_executor: 1, can_suspend: 1, can_reject: 1, can_delete_report: 1 }))
    requestApi.completeAct.mockResolvedValue({ lockVersion: 2 })
    const { app } = mountDetails(ref(1))
    await flushRequests()
    const process = document.querySelector('[aria-label="Действия с заявкой"]')
    expect(process.textContent).toContain('Завершить заявку')
    expect(process.textContent).toContain('Приостановить')
    expect(process.textContent).toContain('Отказать')
    expect(process.textContent).not.toContain('Переназначить')
    expect(document.querySelector('[aria-label="Назначение исполнителя"]').textContent).toContain('Переназначить')
    expect(document.querySelector('[aria-label="Действия с отчётом"]').textContent).toContain('Загрузить отчёт')
    button('Завершить заявку').click()
    await nextTick()
    expect(requestApi.completeAct).not.toHaveBeenCalled()
    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog.textContent).toContain('Отчёт станет доступен всем сотрудникам')
    ;[...dialog.querySelectorAll('button')].find(item => item.textContent.trim() === 'Завершить заявку').click()
    await flushRequests()
    expect(requestApi.completeAct).toHaveBeenCalledWith(1, 1)
    app.unmount()
  })

  it.each([['Согласовано', 'approve'], ['Не согласовано', 'decline']])('confirms %s with completion stated explicitly', async (label, decision) => {
    requestApi.get.mockResolvedValue(routed(1, { route: 'protocol', status: 'security_review', can_choose_route: 0, can_security_decide: 1 }))
    requestApi.decideSecurity.mockResolvedValue({ lockVersion: 2 })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    expect(root.textContent).not.toContain('Вернуть в работу')
    button(label).click()
    await nextTick()
    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog.textContent).toContain('Заявка будет выполнена независимо от решения СБ')
    ;[...dialog.querySelectorAll('button')].find(item => item.textContent.trim() === label).click()
    await flushRequests()
    expect(requestApi.decideSecurity).toHaveBeenCalledWith(1, decision, null, 1)
    app.unmount()
  })
})
