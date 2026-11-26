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
    closeRequest: vi.fn(),
    publishOpinion: vi.fn(),
    decideSecurity: vi.fn(),
    correctSecurityDecision: vi.fn(),
    deleteOpinion: vi.fn(),
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
      'Без направления', 'Геометрические измерения', 'Механические испытания',
      'Электротехнические испытания', 'Резерв 1', 'Резерв 2',
    ])
    expect(root.querySelector('.request-color-control summary').textContent).toContain('Механические испытания')
    options[1].click()
    await flushRequests()
    expect(requestApi.setColor).toHaveBeenCalledWith(1, 'orange', 1)
    expect(root.querySelector('.request-color-control summary').textContent).toContain('Геометрические измерения')
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


describe('RequestDetails closure and security decisions', () => {
  const routed = (id, extra = {}) => {
    const details = requestDetails(id, `Образец ${id}`)
    Object.assign(details.item, { can_close_request: 1, ...extra })
    return details
  }
  const button = label => [...document.querySelectorAll('button')].find(item => item.textContent.trim() === label)
  async function closeAndConfirm() {
    button('Закрыть заявку').click()
    await nextTick()
    const dialog = document.querySelector('[role="alertdialog"]')
    ;[...dialog.querySelectorAll('button')].find(item => item.textContent.trim() === 'Закрыть заявку').click()
    await flushRequests()
  }

  it('offers assignment immediately without asking for a route', async () => {
    requestApi.get.mockResolvedValue(routed(1, { route: null, status: 'registered', can_close_request: 0, can_assign_executor: 1, can_upload_report: 0 }))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    expect(root.querySelector('#request-route')).toBeNull()
    expect(root.textContent).not.toContain('Маршрут')
    expect(root.querySelector('select[aria-label="Исполнитель ИЦ"]')).not.toBeNull()
    expect(root.querySelector('.request-closure-options')).toBeNull()
    app.unmount()
  })

  it.each([[true, true], [true, false], [false, false]])('closes with expertise %s and security %s', async (expertise, security) => {
    requestApi.get.mockResolvedValueOnce(routed(1)).mockResolvedValueOnce(routed(1, {
      route: expertise ? 'protocol' : 'act', security_review_required: Number(security),
      status: expertise ? 'opinion_preparation' : 'completed', can_close_request: 0, lockVersion: 2,
    }))
    requestApi.closeRequest.mockResolvedValue({ lockVersion: 2 })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    const inputs = root.querySelectorAll('.request-closure-options input')
    expect([...inputs].map(input => input.checked)).toEqual([true, true])
    if (!expertise) inputs[0].click()
    else if (!security) inputs[1].click()
    await nextTick()
    if (!expertise) {
      expect(inputs[1].checked).toBe(false)
      expect(inputs[1].disabled).toBe(true)
    }
    button('Закрыть заявку').click()
    await nextTick()
    expect(requestApi.closeRequest).not.toHaveBeenCalled()
    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog.textContent).toContain(!expertise ? 'без экспертизы и контроля СБ' : security ? 'затем на контроль СБ' : 'после публикации заключения без контроля СБ')
    ;[...dialog.querySelectorAll('button')].find(item => item.textContent.trim() === 'Закрыть заявку').click()
    await flushRequests()
    expect(requestApi.closeRequest).toHaveBeenCalledWith(1, expertise, security, 1)
    expect(root.querySelectorAll('.process-timeline li')).toHaveLength(security ? 5 : expertise ? 4 : 3)
    expect(root.querySelector('.request-closure-options')).toBeNull()
    app.unmount()
  })

  it('does not close when confirmation is cancelled', async () => {
    requestApi.get.mockResolvedValue(routed(1))
    const { app } = mountDetails(ref(1))
    await flushRequests()
    button('Закрыть заявку').click()
    await nextTick()
    button('Отмена').click()
    await flushRequests()
    expect(requestApi.closeRequest).not.toHaveBeenCalled()
    app.unmount()
  })

  it.each([403, 500])('shows a closure failure (%i) without reporting success', async status => {
    requestApi.get.mockResolvedValue(routed(1))
    requestApi.closeRequest.mockRejectedValue(Object.assign(new Error('Rejected'), { status }))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    await closeAndConfirm()
    expect(root.textContent).toContain(status === 403 ? 'Закрытие доступно руководителям' : 'Не удалось закрыть заявку')
    expect(button('Закрыть заявку').disabled).toBe(false)
    app.unmount()
  })

  it('refreshes the report and version after a conflict', async () => {
    requestApi.get.mockResolvedValueOnce(routed(1)).mockResolvedValueOnce(routed(1, { lockVersion: 2 }))
    requestApi.closeRequest.mockRejectedValue(Object.assign(new Error('Conflict'), { status: 409 }))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    await closeAndConfirm()
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(2)
    expect(root.textContent).toContain('Заявка уже изменена')
    app.unmount()
  })

  it('ignores a closure response after closing the card', async () => {
    const pending = deferred()
    requestApi.get.mockResolvedValue(routed(1))
    requestApi.closeRequest.mockReturnValue(pending.promise)
    const { app } = mountDetails(ref(1))
    await flushRequests()
    await closeAndConfirm()
    app.unmount()
    pending.resolve({ lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(1)
  })

  it('ignores an old closure after another card has been updated and resets defaults', async () => {
    const pending = deferred()
    const id = ref(1)
    requestApi.get.mockImplementation(value => Promise.resolve(routed(value)))
    requestApi.closeRequest.mockReturnValueOnce(pending.promise).mockResolvedValueOnce({ lockVersion: 2 })
    const { app, root } = mountDetails(id)
    await flushRequests()
    root.querySelector('.request-closure-options input').click()
    await nextTick()
    await closeAndConfirm()
    id.value = 2
    await flushRequests()
    expect([...root.querySelectorAll('.request-closure-options input')].map(input => input.checked)).toEqual([true, true])
    await closeAndConfirm()
    const calls = requestApi.get.mock.calls.length
    pending.resolve({ lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(calls)
    expect(root.textContent).toContain('Образец 2')
    app.unmount()
  })

  it('makes publication completion explicit for the expert and hides route settings', async () => {
    requestApi.get.mockResolvedValueOnce(routed(1, {
      route: 'protocol', security_review_required: 0, status: 'opinion_preparation',
      can_close_request: 0, can_publish_opinion: 1,
    })).mockResolvedValueOnce(routed(1, { route: 'protocol', security_review_required: 0, status: 'completed', can_close_request: 0 }))
    requestApi.publishOpinion.mockResolvedValue({ status: 'completed', lockVersion: 2 })
    const { app, root } = mountDetails(ref(1))
    try {
      await flushRequests()
      expect(button('Изменить маршрут')).toBeUndefined()
      button('Написать заключение').click()
      await nextTick()
      const form = document.querySelector('[aria-labelledby="opinion-modal-title"]')
      expect(form.textContent).toContain('После публикации заключения заявка будет выполнена')
      const input = form.querySelector('textarea')
      input.value = 'Образцы соответствуют требованиям'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      await nextTick()
      expect(button('Опубликовать и передать в СБ')).toBeUndefined()
      expect(button('Опубликовать и завершить заявку')).toBeDefined()
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await flushRequests()
      expect(requestApi.publishOpinion).toHaveBeenCalledWith(1, 'Образцы соответствуют требованиям', 1)
      expect(root.textContent).toContain('Заявка выполнена')
    } finally {
      app.unmount()
    }
  })

  it('closes a stale opinion form and refreshes the route after a conflict', async () => {
    requestApi.get.mockResolvedValueOnce(routed(1, {
      route: 'protocol', status: 'opinion_preparation', can_close_request: 0, can_publish_opinion: 1,
    })).mockResolvedValueOnce(routed(1, { route: 'protocol', status: 'in_progress', can_close_request: 1, can_publish_opinion: 0, lockVersion: 2 }))
    requestApi.publishOpinion.mockRejectedValue({ status: 409 })
    const { app, root } = mountDetails(ref(1))
    try {
      await flushRequests()
      button('Написать заключение').click()
      await nextTick()
      const form = document.querySelector('[aria-labelledby="opinion-modal-title"]')
      const input = form.querySelector('textarea')
      input.value = 'Заключение из открытой ранее формы'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      await nextTick()
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await flushRequests()
      expect(document.querySelector('[aria-labelledby="opinion-modal-title"]')).toBeNull()
      expect(root.textContent).toContain('Проверьте отчёт и выбранные проверки')
      expect(root.querySelector('.request-closure-options')).not.toBeNull()
    } finally {
      app.unmount()
    }
  })

  it.each([403, 422, 500])('keeps the security decision available when saving fails (%i)', async status => {
    requestApi.get.mockResolvedValue(routed(1, { route: 'protocol', status: 'security_review', can_security_decide: 1 }))
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

  it.each([['Согласовано', 'approve'], ['Не согласовано', 'decline']])('confirms %s with completion stated explicitly', async (label, decision) => {
    requestApi.get.mockResolvedValue(routed(1, { route: 'protocol', status: 'security_review', can_security_decide: 1 }))
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

describe('Security decision correction', () => {
  const details = (id, extra = {}) => {
    const result = requestDetails(id, `Образец ${id}`)
    Object.assign(result.item, { route: 'protocol', status: 'completed', security_mark: 'approve', can_correct_security_decision: 1, ...extra })
    return result
  }
  const button = label => [...document.querySelectorAll('button')].find(item => item.textContent.trim() === label)
  async function fillCorrection() {
    button('Исправить решение СБ').click()
    await nextTick()
    const form = document.querySelector('[aria-labelledby="security-correction-title"]')
    for (const [selector, value, event] of [['select', 'decline', 'change'], ['textarea', ' Ошибка выбора ', 'input'], ['input', ' IT-360 ', 'input']]) {
      const field = form.querySelector(selector)
      field.value = value
      field.dispatchEvent(new Event(event, { bubbles: true }))
    }
    await nextTick()
    return form
  }

  it('requires all fields, sends the original version and displays preserved history', async () => {
    requestApi.get.mockResolvedValueOnce(details(1)).mockResolvedValueOnce({ ...details(1, { security_mark: 'decline', lockVersion: 2 }), history: [
      { id: 1, kind: 'transition', action: 'security_approve', actorName: 'Сотрудник СБ', occurredAt: '2026-10-05T10:00:00Z' },
      { id: 1, kind: 'security_correction', action: 'correct_security_decision', actorName: 'Администратор', reason: 'Согласовано → Не согласовано. Причина: Ошибка выбора. Обращение в ИТ: IT-360', occurredAt: '2026-10-05T11:00:00Z' },
    ] })
    requestApi.correctSecurityDecision.mockResolvedValue({ lockVersion: 2 })
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    button('Исправить решение СБ').click()
    await nextTick()
    expect(button('Сохранить исправление').disabled).toBe(true)
    button('Отмена').click()
    await nextTick()
    const form = await fillCorrection()
    expect(form.textContent).toContain('Исходное решение и история исправлений сохранятся. Статус заявки и документы не изменятся.')
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flushRequests()
    expect(requestApi.correctSecurityDecision).toHaveBeenCalledWith(1, 'decline', 'Ошибка выбора', 'IT-360', 1)
    expect(root.textContent).toContain('Исправлено решение СБ')
    expect(root.textContent).toContain('IT-360')
    expect(root.textContent).toContain('СБ: согласовано, заявка выполнена')
    app.unmount()
  })

  it('hides the action without permission', async () => {
    requestApi.get.mockResolvedValue(details(1, { can_correct_security_decision: 0 }))
    const { app } = mountDetails(ref(1))
    await flushRequests()
    expect(button('Исправить решение СБ')).toBeUndefined()
    app.unmount()
  })

  it('ignores an old correction response after a new request finishes', async () => {
    const pending = deferred()
    requestApi.get.mockImplementation(id => Promise.resolve(details(id)))
    requestApi.correctSecurityDecision.mockReturnValueOnce(pending.promise).mockResolvedValueOnce({ lockVersion: 2 })
    const id = ref(1)
    const { app, root } = mountDetails(id)
    await flushRequests()
    ;(await fillCorrection()).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flushRequests()
    id.value = 2
    await flushRequests()
    ;(await fillCorrection()).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flushRequests()
    const calls = requestApi.get.mock.calls.length
    pending.resolve({ lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(calls)
    expect(root.textContent).toContain('Образец 2')
    app.unmount()
  })

  it('ignores a late correction response after closing the card', async () => {
    const pending = deferred()
    requestApi.get.mockResolvedValue(details(1))
    requestApi.correctSecurityDecision.mockReturnValue(pending.promise)
    const { app } = mountDetails(ref(1))
    await flushRequests()
    ;(await fillCorrection()).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flushRequests()
    app.unmount()
    pending.resolve({ lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(1)
  })

  it('ignores a late conflict refresh after another card has been corrected', async () => {
    const refresh = deferred()
    requestApi.get.mockResolvedValueOnce(details(1))
      .mockReturnValueOnce(refresh.promise)
      .mockResolvedValueOnce(details(2))
      .mockResolvedValueOnce(details(2, { security_mark: 'decline', lockVersion: 2 }))
    requestApi.correctSecurityDecision.mockRejectedValueOnce({ status: 409 })
      .mockResolvedValueOnce({ lockVersion: 2 })
    const id = ref(1)
    const { app, root } = mountDetails(id)
    try {
      await flushRequests()
      ;(await fillCorrection()).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await flushRequests()
      expect(requestApi.get).toHaveBeenCalledTimes(2)
      id.value = 2
      await flushRequests()
      ;(await fillCorrection()).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await flushRequests()
      expect(requestApi.correctSecurityDecision).toHaveBeenLastCalledWith(2, 'decline', 'Ошибка выбора', 'IT-360', 1)
      expect(requestApi.get).toHaveBeenCalledTimes(4)
      refresh.resolve(details(1, { lockVersion: 2 }))
      await flushRequests()
      expect(root.textContent).toContain('Образец 2')
      expect(root.textContent).not.toContain('Заявка уже изменена')
    } finally {
      app.unmount()
    }
  })

  it('refreshes the current decision and explains a correction conflict in the same card', async () => {
    requestApi.get.mockResolvedValueOnce(details(1))
      .mockResolvedValueOnce(details(1, { security_mark: 'decline', lockVersion: 2 }))
    requestApi.correctSecurityDecision.mockRejectedValueOnce({ status: 409 })
    const { app, root } = mountDetails(ref(1))
    try {
      await flushRequests()
      ;(await fillCorrection()).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await flushRequests()
      expect(requestApi.get).toHaveBeenCalledTimes(2)
      expect(root.textContent).toContain('Проверьте решение СБ перед исправлением. Данные обновлены')
      expect(root.textContent).toContain('Не согласовано')
      expect(document.querySelector('[aria-labelledby="security-correction-title"]')).toBeNull()
    } finally {
      app.unmount()
    }
  })
})


describe('opinion deletion', () => {
  function deletable(id = 1) {
    const response = requestDetails(id, `Образец ${id}`)
    Object.assign(response.item, { can_delete_opinion: 1, can_upload_report: 0, status: 'completed', route: 'protocol' })
    return response
  }
  async function confirmDeletion() {
    const button = [...document.querySelectorAll('button')].find(item => item.textContent.trim() === 'Удалить заключение')
    button.click()
    await nextTick()
    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog.textContent).toContain('Прежнее решение СБ перестанет действовать')
    expect(dialog.textContent).toContain('Отчёт испытаний сохранится')
    const reason = dialog.querySelector('textarea')
    reason.value = 'Неверное заключение'
    reason.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    ;[...dialog.querySelectorAll('button')].find(item => item.textContent.trim() === 'Удалить').click()
    await flushRequests()
  }
  it('deletes with reason and refreshes the reopened request', async () => {
    requestApi.get.mockResolvedValue(deletable())
    requestApi.deleteOpinion.mockResolvedValue({ status: 'opinion_preparation', lockVersion: 2 })
    const { app } = mountDetails(ref(1))
    await flushRequests()
    expect(requestApi.deleteOpinion).not.toHaveBeenCalled()
    await confirmDeletion()
    expect(requestApi.deleteOpinion).toHaveBeenCalledWith(1, 1, 'Неверное заключение')
    expect(requestApi.get).toHaveBeenCalledTimes(2)
    app.unmount()
  })
  it.each(['switch', 'close'])('ignores late deletion after %s', async action => {
    requestApi.get.mockImplementation(id => Promise.resolve(deletable(id)))
    const pending = deferred()
    requestApi.deleteOpinion.mockReturnValue(pending.promise)
    const id = ref(1)
    const { app, root } = mountDetails(id)
    await flushRequests()
    await confirmDeletion()
    if (action === 'close') app.unmount()
    else { id.value = 2; await flushRequests() }
    const calls = requestApi.get.mock.calls.length
    pending.resolve({ status: 'opinion_preparation', lockVersion: 2 })
    await flushRequests()
    expect(requestApi.get).toHaveBeenCalledTimes(calls)
    if (action === 'switch') {
      expect(root.textContent).toContain('Образец 2')
      app.unmount()
    }
  })
  it.each(['switch', 'close'])('ignores a late conflict refresh after %s', async action => {
    const refresh = deferred()
    requestApi.get.mockResolvedValueOnce(deletable(1)).mockReturnValueOnce(refresh.promise).mockResolvedValue(deletable(2))
    requestApi.deleteOpinion.mockRejectedValue({ status: 409 })
    const id = ref(1)
    const { app, root } = mountDetails(id)
    await flushRequests()
    await confirmDeletion()
    expect(requestApi.get).toHaveBeenCalledTimes(2)
    if (action === 'close') app.unmount()
    else { id.value = 2; await flushRequests() }
    refresh.resolve(deletable(1))
    await flushRequests()
    expect(root.textContent).not.toContain('Заявка уже изменена')
    expect(root.textContent).not.toContain('Данные обновлены')
    if (action === 'switch') {
      expect(root.textContent).toContain('Образец 2')
      app.unmount()
    }
  })
  it('shows no deletion action without permission', async () => {
    requestApi.get.mockResolvedValue(requestDetails(1, 'Образец'))
    const { app, root } = mountDetails(ref(1))
    await flushRequests()
    expect(root.textContent).not.toContain('Удалить заключение')
    app.unmount()
  })
})
