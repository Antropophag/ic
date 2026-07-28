import { afterEach, expect, it, vi } from 'vitest'
import { requestApi } from './api'

afterEach(() => {
  vi.unstubAllGlobals()
})

it('loads the registry as JSON', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(
    JSON.stringify({ items: [] }),
    { status: 200, headers: { 'Content-Type': 'application/json' } },
  ))
  vi.stubGlobal('fetch', fetchMock)

  await expect(requestApi.list()).resolves.toEqual({ items: [] })
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests', expect.objectContaining({
    headers: expect.objectContaining({ Accept: 'application/json' }),
  }))
})

it('sends creation data as JSON', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.create({ productName: 'Образец' })

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ productName: 'Образец' }),
    headers: expect.objectContaining({ 'Content-Type': 'application/json' }),
  }))
})

it('exposes HTTP status and validation payload', async () => {
  const payload = { message: 'Validation failed', errors: { productName: ['Required'] } }
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
    JSON.stringify(payload),
    { status: 422, headers: { 'Content-Type': 'application/json' } },
  )))

  await expect(requestApi.create({})).rejects.toMatchObject({ status: 422, payload })
})

it('uses a fallback message for a non-JSON server error', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('broken', { status: 500 })))

  await expect(requestApi.list()).rejects.toThrow('Ошибка обращения к серверу')
})
