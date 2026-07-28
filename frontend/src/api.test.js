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

it('adds a comment as JSON', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.addComment(7, 'Текст')
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/comments', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ body: 'Текст' }),
  }))
})

it('loads an older comment page by cursor', async () => {
  const payload = { items: [], hasMore: false, nextBeforeId: null }
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(payload), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await expect(requestApi.comments(7, 51)).resolves.toEqual(payload)
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/comments?beforeId=51', expect.any(Object))
})

it('uploads a document as multipart data and downloads its bytes', async () => {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response('{}', { status: 201 }))
    .mockResolvedValueOnce(new Response('pdf', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)
  const file = new File(['pdf'], 'report.pdf', { type: 'application/pdf' })

  await requestApi.uploadDocument(7, file)
  await expect(requestApi.downloadDocument(12)).resolves.toBeInstanceOf(Blob)

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/requests/7/documents', expect.objectContaining({
    method: 'POST',
    body: expect.any(FormData),
  }))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/document-versions/12/download', expect.any(Object))
})

it('uploads a test report through the dedicated endpoint', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)
  const file = new File(['pdf'], 'test-report.pdf', { type: 'application/pdf' })

  await requestApi.uploadReport(7, file)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/report', expect.objectContaining({
    method: 'POST',
    body: expect.any(FormData),
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

it('loads and assigns an expert with optimistic locking', async () => {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200 }))
    .mockResolvedValueOnce(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.experts()
  await requestApi.assignExpert(7, 4, 5)

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/experts', expect.any(Object))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/requests/7/expert', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ expertId: 4, lockVersion: 5 }),
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
