// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { requestApi } from '../api'
import TechnicalSpecificationAi, { resetTechnicalSpecificationAiSessions, setTechnicalSpecificationAiPrincipal } from './TechnicalSpecificationAi.vue'

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

async function mount(initialRequestId = 7) {
  const root = document.createElement('div')
  document.body.append(root)
  const requestId = ref(initialRequestId)
  const app = createApp({ render: () => h(TechnicalSpecificationAi, { requestId: requestId.value }) })
  app.mount(root)
  await nextTick()
  return { root, app, requestId }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
  await nextTick()
}

function clickTab(label) {
  Array.from(document.body.querySelectorAll('[role="tab"]')).find(button => button.textContent.includes(label)).click()
}

async function openAndStart(root) {
  root.querySelector('.request-ai-action').click()
  await nextTick()
  Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === 'Запустить обработку').click()
  await nextTick()
}

describe('TechnicalSpecificationAi', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    setTechnicalSpecificationAiPrincipal('test-user')
    resetTechnicalSpecificationAiSessions()
  })
  afterEach(() => { document.body.innerHTML = '' })

  it('opens without requests and starts both tasks from the modal action', async () => {
    const analysis = deferred(); const draft = deferred()
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockReturnValue(analysis.promise)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockReturnValue(draft.promise)
    const { root, app } = await mount()
    root.querySelector('.request-ai-action').click(); await nextTick()
    expect(requestApi.analyzeTechnicalSpecification).not.toHaveBeenCalled()
    expect(requestApi.createTestSpecificationDraft).not.toHaveBeenCalled()
    Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === 'Запустить обработку').click()
    await nextTick()
    expect(requestApi.analyzeTechnicalSpecification).toHaveBeenCalledWith(7, null, expect.any(AbortSignal), true)
    expect(requestApi.createTestSpecificationDraft).toHaveBeenCalledWith(7, null, expect.any(AbortSignal), true)
    expect(document.body.textContent).toContain('Анализ ТЗ В работе')
    expect(document.body.textContent).toContain('Черновик ТЗ на испытания В работе')
    expect(document.body.textContent).toContain('Сверяем требования с заводской реальностью')
    app.unmount()
  })

  it('shows completed analysis while draft remains loading', async () => {
    const analysis = deferred(); const draft = deferred()
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockReturnValue(analysis.promise)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockReturnValue(draft.promise)
    const { root, app } = await mount()
    await openAndStart(root)
    analysis.resolve({ status: 'completed', analysis: { criticalContradictions: ['Конфликт'] } }); await flush()
    expect(document.body.textContent).toContain('Конфликт')
    expect(document.body.textContent).toContain('Черновик ТЗ на испытания В работе')
    clickTab('Черновик ТЗ'); await nextTick()
    expect(document.querySelector('#ai-draft-panel').textContent).toContain('ЛИЗА формирует черновик')
    app.unmount()
  })

  it('shows completed draft while analysis remains loading', async () => {
    const analysis = deferred(); const draft = deferred()
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockReturnValue(analysis.promise)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockReturnValue(draft.promise)
    const { root, app } = await mount()
    await openAndStart(root)
    draft.resolve({ status: 'completed', draft: 'Самостоятельный черновик' }); await flush()
    clickTab('Черновик ТЗ'); await nextTick()
    expect(document.querySelector('#ai-draft-panel').textContent).toContain('Самостоятельный черновик')
    expect(document.body.textContent).toContain('Анализ ТЗ В работе')
    app.unmount()
  })

  it('keeps successful analysis when draft fails', async () => {
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockResolvedValue({ status: 'completed', analysis: { criticalContradictions: ['Готово'] } })
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockRejectedValue(Object.assign(new Error('draft failed'), { status: 503 }))
    const { root, app } = await mount()
    await openAndStart(root); await flush()
    expect(document.body.textContent).toContain('Готово')
    expect(document.body.textContent).toContain('Черновик ТЗ на испытания Ошибка')
    clickTab('Черновик ТЗ'); await nextTick()
    expect(document.querySelector('#ai-draft-panel').textContent).toContain('draft failed')
    app.unmount()
  })

  it('starts a new idempotency intent only when retrying a terminal HTTP failure', async () => {
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification')
      .mockRejectedValueOnce(Object.assign(new Error('terminal'), { status: 503 }))
      .mockResolvedValueOnce({ status: 'completed', analysis: { criticalContradictions: ['После retry'] } })
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockResolvedValue({ status: 'completed', draft: 'Черновик' })
    const { root, app } = await mount()
    await openAndStart(root); await flush()
    Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === 'Повторить анализ').click()
    await flush()

    expect(requestApi.analyzeTechnicalSpecification).toHaveBeenLastCalledWith(7, null, expect.any(AbortSignal), true)
    expect(document.body.textContent).toContain('После retry')
    app.unmount()
  })

  it('keeps the same idempotency intent when retrying an ambiguous network failure', async () => {
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification')
      .mockRejectedValueOnce(new Error('network outcome unknown'))
      .mockResolvedValueOnce({ status: 'completed', analysis: { criticalContradictions: ['Восстановлено'] } })
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockResolvedValue({ status: 'completed', draft: 'Черновик' })
    const { root, app } = await mount()
    await openAndStart(root); await flush()
    Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === 'Повторить анализ').click()
    await flush()

    expect(requestApi.analyzeTechnicalSpecification).toHaveBeenLastCalledWith(7, null, expect.any(AbortSignal), false)
    expect(document.body.textContent).toContain('Восстановлено')
    app.unmount()
  })

  it('uses the same explicit document for two independent requests', async () => {
    const choice = { status: 'choice_required', documents: [{ versionId: 12, name: 'ТЗ.pdf', version: 2, mimeType: 'application/pdf' }] }
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockResolvedValue(choice)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockResolvedValue(choice)
    const { root, app } = await mount()
    await openAndStart(root); await flush()
    document.body.querySelector('.request-ai-choice button').click(); await flush()
    expect(requestApi.analyzeTechnicalSpecification).toHaveBeenLastCalledWith(7, 12, expect.any(AbortSignal), true)
    expect(requestApi.createTestSpecificationDraft).toHaveBeenLastCalledWith(7, 12, expect.any(AbortSignal), true)
    app.unmount()
  })

  it('aborts both tasks on close and ignores their late results', async () => {
    const analysis = deferred(); const draft = deferred()
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockReturnValue(analysis.promise)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockReturnValue(draft.promise)
    const { root, app } = await mount()
    await openAndStart(root)
    const analysisSignal = requestApi.analyzeTechnicalSpecification.mock.calls[0][2]
    const draftSignal = requestApi.createTestSpecificationDraft.mock.calls[0][2]
    Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === 'Закрыть').click()
    expect(analysisSignal.aborted).toBe(true); expect(draftSignal.aborted).toBe(true)
    analysis.resolve({ status: 'completed', analysis: { criticalContradictions: ['Результат после закрытия'] } })
    draft.resolve({ status: 'completed', draft: 'Черновик после закрытия' })
    await flush()

    root.querySelector('.request-ai-action').click(); await nextTick()
    expect(document.body.textContent).not.toContain('Результат после закрытия')
    expect(document.body.textContent).not.toContain('Черновик после закрытия')
    expect(document.body.textContent).toContain('Запустить обработку')
    app.unmount()
  })

  it('aborts both tasks on unmount and does not cache their late results', async () => {
    const analysis = deferred(); const draft = deferred()
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockReturnValue(analysis.promise)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockReturnValue(draft.promise)
    const { root, app } = await mount()
    await openAndStart(root)
    const analysisSignal = requestApi.analyzeTechnicalSpecification.mock.calls[0][2]
    const draftSignal = requestApi.createTestSpecificationDraft.mock.calls[0][2]

    app.unmount()
    expect(analysisSignal.aborted).toBe(true); expect(draftSignal.aborted).toBe(true)
    analysis.resolve({ status: 'completed', analysis: { criticalContradictions: ['Сохранённый анализ'] } })
    draft.resolve({ status: 'completed', draft: 'Сохранённый черновик' })
    await flush()

    const reopened = await mount()
    reopened.root.querySelector('.request-ai-action').click(); await nextTick()

    expect(document.body.textContent).not.toContain('Сохранённый анализ')
    expect(document.body.textContent).not.toContain('Сохранённый черновик')
    expect(document.body.textContent).toContain('Запустить обработку')
    expect(requestApi.analyzeTechnicalSpecification).toHaveBeenCalledTimes(1)
    expect(requestApi.createTestSpecificationDraft).toHaveBeenCalledTimes(1)
    reopened.app.unmount()
  })

  it('aborts request A when switching to B and ignores late A results', async () => {
    const oldAnalysis = deferred(); const oldDraft = deferred()
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockReturnValue(oldAnalysis.promise)
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockReturnValue(oldDraft.promise)
    const { root, app, requestId } = await mount()
    await openAndStart(root)
    const analysisSignal = requestApi.analyzeTechnicalSpecification.mock.calls[0][2]
    const draftSignal = requestApi.createTestSpecificationDraft.mock.calls[0][2]
    requestId.value = 8; await nextTick()
    oldAnalysis.resolve({ status: 'completed', analysis: { criticalContradictions: ['Старый результат'] } })
    oldDraft.resolve({ status: 'completed', draft: 'Старый черновик' }); await flush()
    expect(analysisSignal.aborted).toBe(true); expect(draftSignal.aborted).toBe(true)
    expect(document.body.textContent).not.toContain('Старый результат')
    expect(document.body.textContent).not.toContain('Старый черновик')
    requestId.value = 7; await nextTick()
    root.querySelector('.request-ai-action').click(); await nextTick()
    expect(document.body.textContent).not.toContain('Старый результат')
    expect(document.body.textContent).not.toContain('Старый черновик')
    expect(document.body.textContent).toContain('Запустить обработку')
    app.unmount()
  })

  it('aborts the previous corresponding operation on explicit restart', async () => {
    const oldAnalysis = deferred()
    const choice = { status: 'choice_required', documents: [{ versionId: 12, name: 'ТЗ.pdf', version: 2, mimeType: 'application/pdf' }] }
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification')
      .mockReturnValueOnce(oldAnalysis.promise)
      .mockResolvedValueOnce({ status: 'completed', analysis: { criticalContradictions: ['Новый анализ'] } })
    vi.spyOn(requestApi, 'createTestSpecificationDraft')
      .mockResolvedValueOnce(choice)
      .mockResolvedValueOnce({ status: 'completed', draft: 'Новый черновик' })
    const { root, app } = await mount()
    await openAndStart(root); await flush()
    const oldSignal = requestApi.analyzeTechnicalSpecification.mock.calls[0][2]

    document.body.querySelector('.request-ai-choice button').click(); await flush()

    expect(oldSignal.aborted).toBe(true)
    expect(requestApi.analyzeTechnicalSpecification).toHaveBeenCalledTimes(2)
    expect(document.body.textContent).toContain('Новый анализ')
    oldAnalysis.resolve({ status: 'completed', analysis: { criticalContradictions: ['Устаревший анализ'] } })
    await flush()
    expect(document.body.textContent).not.toContain('Устаревший анализ')
    app.unmount()
  })

  it('does not show AbortError as an AI error', async () => {
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockRejectedValue(new DOMException('cancelled', 'AbortError'))
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockResolvedValue({ status: 'completed', draft: 'Черновик' })
    const { root, app } = await mount()
    await openAndStart(root); await flush()

    expect(document.body.textContent).not.toContain('Анализ ТЗ Ошибка')
    expect(document.body.textContent).not.toContain('Не удалось выполнить AI-анализ')
    app.unmount()
  })

  it('still shows an ordinary backend error', async () => {
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockRejectedValue(Object.assign(new Error('backend failed'), { status: 503 }))
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockResolvedValue({ status: 'completed', draft: 'Черновик' })
    const { root, app } = await mount()
    await openAndStart(root); await flush()

    expect(document.body.textContent).toContain('Анализ ТЗ Ошибка')
    expect(document.body.textContent).toContain('backend failed')
    app.unmount()
  })

  it('does not expose cached AI results after logout and login as another user', async () => {
    vi.spyOn(requestApi, 'analyzeTechnicalSpecification').mockResolvedValue({ status: 'completed', analysis: { criticalContradictions: ['Результат пользователя A'] } })
    vi.spyOn(requestApi, 'createTestSpecificationDraft').mockResolvedValue({ status: 'completed', draft: 'Черновик пользователя A' })
    setTechnicalSpecificationAiPrincipal('user-a')
    const first = await mount(7)
    await openAndStart(first.root); await flush()
    first.app.unmount()

    setTechnicalSpecificationAiPrincipal(null)
    setTechnicalSpecificationAiPrincipal('user-b')
    const second = await mount(7)
    second.root.querySelector('.request-ai-action').click(); await nextTick()

    expect(document.body.textContent).not.toContain('Результат пользователя A')
    expect(document.body.textContent).not.toContain('Черновик пользователя A')
    expect(document.body.textContent).toContain('Запустить обработку')
    second.app.unmount()
  })
})
