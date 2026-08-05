import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApplicationDraftForm } from './applicationDraftForm'
import { loadApplicationDraft, saveApplicationDraft } from './applicationDraftStorage'

const defaults = () => ({
  productName: '',
  manufacturer: '',
  supplier: '',
  sampleQuantity: 1,
  testMethod: '',
  comment: '',
})

function memoryStorage() {
  const values = new Map()
  return {
    getItem: vi.fn(key => values.get(key) ?? null),
    setItem: vi.fn((key, value) => values.set(key, value)),
    removeItem: vi.fn(key => values.delete(key)),
  }
}

function formFor(userId, draft = defaults(), selectedFiles = []) {
  const notices = []
  const form = createApplicationDraftForm({
    userId,
    draft,
    files: () => selectedFiles,
    notify: message => notices.push(message),
  })
  return { draft, form, notices }
}

describe('application create draft form lifecycle', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-05T00:00:00.000Z'))
    vi.stubGlobal('localStorage', memoryStorage())
    vi.stubGlobal('window', { clearTimeout, setTimeout })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('restores saved values over defaults and shows a notice', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'Восстановлено', sampleQuantity: 4 }, false)
    const state = formFor(3)
    state.form.restore()

    expect(state.draft).toMatchObject({ productName: 'Восстановлено', sampleQuantity: 4 })
    expect(state.notices).toEqual(['Черновик заявки восстановлен.'])
  })

  it('restores and notifies only once', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'Один раз' }, false)
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'Изменено после восстановления'
    state.form.restore()

    expect(state.draft.productName).toBe('Изменено после восстановления')
    expect(state.notices).toEqual(['Черновик заявки восстановлен.'])
  })

  it('asks to select files again without restoring file contents', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'С файлами' }, true)
    const state = formFor(3)
    state.form.restore()

    expect(state.notices).toEqual([
      'Черновик заявки восстановлен. Файлы необходимо выбрать повторно.',
    ])
    expect(loadApplicationDraft(3)).not.toHaveProperty('files')
  })

  it('preserves the restored file marker while ordinary fields are edited', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'С файлами' }, true)
    const state = formFor(3)
    state.form.restore()
    state.draft.comment = 'Изменённый комментарий'
    state.form.scheduleSave()
    state.form.flushSave()

    const reopened = formFor(3)
    reopened.form.restore()
    expect(reopened.notices).toEqual([
      'Черновик заявки восстановлен. Файлы необходимо выбрать повторно.',
    ])
  })

  it('clears the restored file marker after the user explicitly clears files', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'С файлами' }, true)
    const state = formFor(3)
    state.form.restore()
    state.draft.comment = 'Без файлов'
    state.form.scheduleFilesSave()
    state.form.flushSave()

    expect(loadApplicationDraft(3).hadFiles).toBe(false)
  })

  it('debounces rapid changes into one final save', () => {
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'П'
    state.form.scheduleSave()
    state.draft.productName = 'Продукт'
    state.form.scheduleSave()

    vi.advanceTimersByTime(499)
    expect(localStorage.setItem).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(localStorage.setItem).toHaveBeenCalledTimes(1)
    expect(loadApplicationDraft(3).data.productName).toBe('Продукт')
  })

  it('removes a draft when all fields return to defaults', () => {
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'Временный ввод'
    state.form.scheduleSave()
    vi.runAllTimers()
    expect(loadApplicationDraft(3)).not.toBeNull()

    Object.assign(state.draft, defaults())
    state.form.scheduleSave()
    vi.runAllTimers()
    expect(loadApplicationDraft(3)).toBeNull()
  })

  it('keeps autosaving other fields with the last valid quantity while quantity is temporarily invalid', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'До изменения', sampleQuantity: 7 }, false)
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'Не потерять'
    state.draft.sampleQuantity = ''
    state.form.scheduleSave()
    vi.runAllTimers()

    expect(loadApplicationDraft(3).data).toMatchObject({
      productName: 'Не потерять',
      sampleQuantity: 7,
    })
  })

  it('remembers a valid quantity seen before the debounced save fires', () => {
    const state = formFor(3)
    state.form.restore()
    state.draft.sampleQuantity = 9
    state.form.scheduleSave()
    state.draft.sampleQuantity = ''
    state.draft.productName = 'Не потерять'
    state.form.scheduleSave()
    vi.runAllTimers()

    expect(loadApplicationDraft(3).data).toMatchObject({
      productName: 'Не потерять',
      sampleQuantity: 9,
    })
  })

  it('stores only the hadFiles marker when files are selected', () => {
    const file = new File(['binary content'], 'document.pdf', { type: 'application/pdf' })
    const state = formFor(3, { ...defaults(), productName: 'С документом' }, [file])
    state.form.restore()
    state.form.scheduleFilesSave()
    vi.runAllTimers()

    const serialized = localStorage.setItem.mock.calls[0][1]
    expect(JSON.parse(serialized).hadFiles).toBe(true)
    expect(serialized).not.toContain('binary content')
    expect(serialized).not.toContain('document.pdf')
  })

  it('removes the draft only after the form reports successful creation', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'Успех' }, false)
    const state = formFor(3)
    state.form.restore()
    state.form.remove()
    expect(loadApplicationDraft(3)).toBeNull()
  })

  it('cancels a pending debounce after successful creation', () => {
    window.clearTimeout = vi.fn()
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'Не должен вернуться'
    state.form.scheduleSave()
    state.form.remove()
    vi.runAllTimers()

    expect(localStorage.setItem).not.toHaveBeenCalled()
    expect(loadApplicationDraft(3)).toBeNull()
  })

  it('keeps the draft when an API error does not trigger successful removal', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'Ошибка API' }, false)
    const state = formFor(3)
    state.form.restore()
    // The component returns from its API error branch without calling remove().
    expect(loadApplicationDraft(3).data.productName).toBe('Ошибка API')
  })

  it('explicit reset removes storage and permits later autosaves', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'Очистить' }, false)
    const state = formFor(3)
    state.form.restore()
    state.form.remove()
    Object.assign(state.draft, defaults())
    state.form.enableSaving()
    expect(loadApplicationDraft(3)).toBeNull()

    state.draft.productName = 'Новый черновик'
    state.form.scheduleSave()
    vi.runAllTimers()
    expect(loadApplicationDraft(3).data.productName).toBe('Новый черновик')
  })

  it('does not recreate a draft from the pending debounce during explicit reset', () => {
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'Очистить до debounce'
    state.form.scheduleSave()
    state.form.remove()
    Object.assign(state.draft, defaults())
    state.form.enableSaving()
    vi.runAllTimers()

    expect(localStorage.setItem).not.toHaveBeenCalled()
    expect(loadApplicationDraft(3)).toBeNull()
  })

  it('isolates drafts by current user ID', () => {
    saveApplicationDraft(3, { ...defaults(), productName: 'Пользователь 3' }, false)
    saveApplicationDraft(4, { ...defaults(), productName: 'Пользователь 4' }, false)
    const state = formFor(4)
    state.form.restore()
    expect(state.draft.productName).toBe('Пользователь 4')
  })

  it('keeps working when localStorage is unavailable', () => {
    localStorage.getItem.mockImplementation(() => { throw new DOMException('blocked', 'SecurityError') })
    localStorage.setItem.mockImplementation(() => { throw new DOMException('blocked', 'SecurityError') })
    const state = formFor(3)
    expect(() => state.form.restore()).not.toThrow()
    state.draft.productName = 'Ввод работает'
    expect(() => {
      state.form.scheduleSave()
      vi.runAllTimers()
    }).not.toThrow()
  })

  it('does not write after unmount', () => {
    const state = formFor(3)
    state.form.restore()
    state.form.scheduleSave()
    state.form.dispose()
    vi.runAllTimers()
    expect(localStorage.setItem).not.toHaveBeenCalled()
  })

  it('flushes pending input when the page is closed', () => {
    const state = formFor(3)
    state.form.restore()
    state.draft.productName = 'Последний ввод'
    state.form.scheduleSave()
    state.form.flushSave()

    expect(localStorage.setItem).toHaveBeenCalledTimes(1)
    expect(loadApplicationDraft(3).data.productName).toBe('Последний ввод')
    vi.runAllTimers()
    expect(localStorage.setItem).toHaveBeenCalledTimes(1)
  })
})
