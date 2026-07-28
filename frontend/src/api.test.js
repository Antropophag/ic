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

it('loads one request card with its history', async () => {
  const payload = { item: { id: 7 }, history: [] }
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(payload), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await expect(requestApi.get(7)).resolves.toEqual(payload)
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7', expect.any(Object))
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

it('assigns an executor and starts a request with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.assignExecutor(7, 2, 3)
  await requestApi.start(7, 3)

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/requests/7/executor', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ executorId: 2, lockVersion: 3 }),
  }))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/requests/7/start', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 3 }),
  }))
})

it('loads active executors from the server', async () => {
  const payload = { items: [{ id: 2, displayName: 'Исполнитель' }] }
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify(payload), { status: 200 })))

  await expect(requestApi.executors()).resolves.toEqual(payload)
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
