import {
  loadApplicationDraft,
  removeApplicationDraft,
  saveApplicationDraft,
} from './applicationDraftStorage'

export const APPLICATION_DRAFT_SAVE_DELAY_MS = 500

export function createApplicationDraftForm({ userId, draft, files, notify }) {
  let saveTimer = null
  let active = true
  let restored = false
  let dirty = false
  let saveGeneration = 0
  let hadFiles = false
  let lastValidQuantity = Number.isSafeInteger(draft.sampleQuantity) && draft.sampleQuantity >= 1
    ? draft.sampleQuantity
    : 1

  function dataForSave() {
    if (Number.isSafeInteger(draft.sampleQuantity) && draft.sampleQuantity >= 1) {
      lastValidQuantity = draft.sampleQuantity
    }
    return { ...draft, sampleQuantity: lastValidQuantity }
  }

  function restore() {
    if (restored) return null
    const saved = loadApplicationDraft(userId)
    if (saved) {
      Object.assign(draft, saved.data)
      lastValidQuantity = saved.data.sampleQuantity
      hadFiles = saved.hadFiles
      notify(saved.hadFiles
        ? 'Черновик заявки восстановлен. Файлы необходимо выбрать повторно.'
        : 'Черновик заявки восстановлен.')
    }
    restored = true
    return saved
  }

  function scheduleSave() {
    if (!restored || !active) return
    if (Number.isSafeInteger(draft.sampleQuantity) && draft.sampleQuantity >= 1) {
      lastValidQuantity = draft.sampleQuantity
    }
    dirty = true
    window.clearTimeout(saveTimer)
    const generation = ++saveGeneration
    saveTimer = window.setTimeout(() => {
      saveTimer = null
      if (active && restored && generation === saveGeneration) {
        saveApplicationDraft(userId, dataForSave(), hadFiles)
        dirty = false
      }
    }, APPLICATION_DRAFT_SAVE_DELAY_MS)
  }

  function scheduleFilesSave() {
    hadFiles = files().length > 0
    scheduleSave()
  }

  function flushSave() {
    if (!restored || !active || !dirty) return
    window.clearTimeout(saveTimer)
    saveTimer = null
    saveGeneration += 1
    saveApplicationDraft(userId, dataForSave(), hadFiles)
    dirty = false
  }

  function remove() {
    window.clearTimeout(saveTimer)
    saveTimer = null
    saveGeneration += 1
    restored = false
    dirty = false
    hadFiles = false
    removeApplicationDraft(userId)
  }

  function enableSaving() {
    restored = true
  }

  function dispose() {
    active = false
    window.clearTimeout(saveTimer)
    saveTimer = null
    saveGeneration += 1
  }

  return {
    dispose,
    enableSaving,
    flushSave,
    remove,
    restore,
    scheduleFilesSave,
    scheduleSave,
  }
}
