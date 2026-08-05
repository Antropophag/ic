import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  APPLICATION_DRAFT_TTL_MS,
  loadApplicationDraft,
  removeApplicationDraft,
  saveApplicationDraft,
} from './applicationDraftStorage'

const data = {
  productName: 'Лифт',
  manufacturer: 'Завод',
  supplier: 'Поставщик',
  sampleQuantity: 2,
  testMethod: 'ГОСТ',
  comment: 'Срочно',
}

function memoryStorage() {
  const values = new Map()
  return {
    getItem: vi.fn(key => values.get(key) ?? null),
    setItem: vi.fn((key, value) => values.set(key, value)),
    removeItem: vi.fn(key => values.delete(key)),
  }
}

describe('application draft storage', () => {
  beforeEach(() => {
    vi.useRealTimers()
    vi.stubGlobal('localStorage', memoryStorage())
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('saves and loads a versioned draft with the current timestamp', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-05T00:00:00.000Z'))
    saveApplicationDraft(7, data, true)

    expect(JSON.parse(localStorage.setItem.mock.calls[0][1])).toEqual({
      version: 1,
      savedAt: '2026-08-05T00:00:00.000Z',
      data,
      hadFiles: true,
    })
    expect(loadApplicationDraft(7)).toEqual({ data, hadFiles: true })
  })

  it('uses different keys for different internal user IDs', () => {
    saveApplicationDraft(7, data, false)
    saveApplicationDraft(8, { ...data, productName: 'Другой лифт' }, false)

    expect(localStorage.setItem.mock.calls.map(([key]) => key)).toEqual([
      'ic.application-create-draft.v1.7',
      'ic.application-create-draft.v1.8',
    ])
    expect(loadApplicationDraft(7).data.productName).toBe('Лифт')
  })

  it('returns null when there is no draft', () => {
    expect(loadApplicationDraft(7)).toBeNull()
  })

  it('does not persist or restore an empty draft', () => {
    saveApplicationDraft(7, {
      productName: '',
      manufacturer: '',
      supplier: '',
      sampleQuantity: 1,
      testMethod: '',
      comment: '',
    }, false)
    expect(localStorage.setItem).not.toHaveBeenCalled()
    expect(localStorage.removeItem).toHaveBeenCalledWith('ic.application-create-draft.v1.7')

    localStorage.setItem('ic.application-create-draft.v1.7', JSON.stringify({
      version: 1,
      savedAt: new Date().toISOString(),
      data: {
        productName: '',
        manufacturer: '',
        supplier: '',
        sampleQuantity: 1,
        testMethod: '',
        comment: '',
      },
      hadFiles: false,
    }))
    expect(loadApplicationDraft(7)).toBeNull()
  })

  it.each([
    ['damaged JSON', '{'],
    ['an unknown version', JSON.stringify({ version: 2, savedAt: new Date().toISOString(), data, hadFiles: false })],
  ])('removes %s', (_name, serialized) => {
    localStorage.setItem('ic.application-create-draft.v1.7', serialized)
    expect(loadApplicationDraft(7)).toBeNull()
    expect(localStorage.removeItem).toHaveBeenCalledWith('ic.application-create-draft.v1.7')
  })

  it('removes a draft older than 30 days', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-05T00:00:00.000Z'))
    localStorage.setItem('ic.application-create-draft.v1.7', JSON.stringify({
      version: 1,
      savedAt: new Date(Date.now() - APPLICATION_DRAFT_TTL_MS - 1).toISOString(),
      data,
      hadFiles: false,
    }))
    expect(loadApplicationDraft(7)).toBeNull()
  })

  it('removes a draft with a future timestamp', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-05T00:00:00.000Z'))
    localStorage.setItem('ic.application-create-draft.v1.7', JSON.stringify({
      version: 1,
      savedAt: new Date(Date.now() + 1).toISOString(),
      data,
      hadFiles: false,
    }))
    expect(loadApplicationDraft(7)).toBeNull()
    expect(localStorage.removeItem).toHaveBeenCalledWith('ic.application-create-draft.v1.7')
  })

  it.each([undefined, null, 0, -1, '7'])('does not access storage for invalid user ID %s', userId => {
    loadApplicationDraft(userId)
    saveApplicationDraft(userId, data, false)
    removeApplicationDraft(userId)
    expect(localStorage.getItem).not.toHaveBeenCalled()
    expect(localStorage.setItem).not.toHaveBeenCalled()
    expect(localStorage.removeItem).not.toHaveBeenCalled()
  })

  it('persists only whitelisted fields and never serializes File or Blob values', () => {
    saveApplicationDraft(7, {
      ...data,
      accessToken: 'secret',
      file: new File(['secret bytes'], 'secret.txt'),
      blob: new Blob(['secret bytes']),
    }, false)

    const payload = JSON.parse(localStorage.setItem.mock.calls[0][1])
    expect(payload.data).toEqual(data)
    expect(JSON.stringify(payload)).not.toContain('secret')
  })

  it('ignores unknown properties while loading', () => {
    localStorage.setItem('ic.application-create-draft.v1.7', JSON.stringify({
      version: 1,
      savedAt: new Date().toISOString(),
      data: { ...data, futureField: 'ignored' },
      hadFiles: false,
      futureMetadata: true,
    }))
    expect(loadApplicationDraft(7)).toEqual({ data, hadFiles: false })
  })

  it.each([
    ['fractional quantity', { ...data, sampleQuantity: 1.5 }],
    ['an array instead of a string', { ...data, supplier: ['Поставщик'] }],
    ['oversized product name', { ...data, productName: 'x'.repeat(501) }],
    ['oversized test method', { ...data, testMethod: 'x'.repeat(10001) }],
    ['oversized comment', { ...data, comment: 'x'.repeat(10001) }],
  ])('rejects %s', (_name, invalidData) => {
    saveApplicationDraft(7, invalidData, false)
    expect(localStorage.setItem).not.toHaveBeenCalled()
  })

  it.each(['getItem', 'setItem', 'removeItem'])('does not throw when %s fails', method => {
    localStorage[method].mockImplementation(() => { throw new DOMException('blocked', 'SecurityError') })
    expect(() => {
      if (method === 'getItem') loadApplicationDraft(7)
      else if (method === 'setItem') saveApplicationDraft(7, data, false)
      else removeApplicationDraft(7)
    }).not.toThrow()
  })

  it('removes a draft explicitly', () => {
    saveApplicationDraft(7, data, false)
    removeApplicationDraft(7)
    expect(loadApplicationDraft(7)).toBeNull()
  })
})
