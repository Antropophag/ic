import { expect, request as playwrightRequest, test } from '@playwright/test'

async function apiFor(baseURL, userId) {
  const bootstrap = await playwrightRequest.newContext({
    baseURL,
    extraHTTPHeaders: { 'X-Test-User-ID': String(userId) },
  })
  const me = await bootstrap.get('/api/v1/auth/me')
  expect(me.ok(), await me.text()).toBe(true)
  const { csrfToken } = await me.json()
  const storageState = await bootstrap.storageState()
  await bootstrap.dispose()
  return playwrightRequest.newContext({
    baseURL,
    storageState,
    extraHTTPHeaders: { 'X-Test-User-ID': String(userId), 'X-CSRF-Token': csrfToken },
  })
}

async function json(response) {
  expect(response.ok(), await response.text()).toBe(true)
  return response.json()
}

test('повторные и параллельные POST возвращают один доменный результат', async ({ baseURL }) => {
  const initiator = await apiFor(baseURL, 3)
  const manager = await apiFor(baseURL, 1)
  try {
    const legacyPost = await initiator.post('/api/v1/requests', {
      data: {
        productName: 'Old frontend must reload', manufacturer: 'E2E', supplier: 'E2E',
        sampleQuantity: 1, testMethod: 'Compatibility rejection',
      },
    })
    expect(legacyPost.status()).toBe(422)

    const createKey = crypto.randomUUID()
    const createOptions = {
      headers: { 'Idempotency-Key': createKey },
      data: {
        productName: `Idempotency-E2E-${Date.now()}`,
        manufacturer: 'E2E',
        supplier: 'E2E',
        sampleQuantity: 1,
        testMethod: 'Parallel POST contract',
      },
    }
    const [firstCreateResponse, secondCreateResponse] = await Promise.all([
      initiator.post('/api/v1/requests', createOptions),
      initiator.post('/api/v1/requests', createOptions),
    ])
    const [firstCreate, secondCreate] = await Promise.all([
      json(firstCreateResponse),
      json(secondCreateResponse),
    ])
    expect(secondCreate).toEqual(firstCreate)
    expect([
      firstCreateResponse.headers()['idempotency-replayed'],
      secondCreateResponse.headers()['idempotency-replayed'],
    ].sort()).toEqual(['false', 'true'])

    const requestId = firstCreate.id
    const commentKey = crypto.randomUUID()
    const commentOptions = { headers: { 'Idempotency-Key': commentKey }, data: { body: 'Один комментарий' } }
    expect(await json(await initiator.post(`/api/v1/requests/${requestId}/comments`, commentOptions)))
      .toEqual(await json(await initiator.post(`/api/v1/requests/${requestId}/comments`, commentOptions)))

    const documentKey = crypto.randomUUID()
    const documentOptions = {
      headers: { 'Idempotency-Key': documentKey },
      multipart: { file: { name: 'same.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
    }
    expect(await json(await initiator.post(`/api/v1/requests/${requestId}/documents`, documentOptions)))
      .toEqual(await json(await initiator.post(`/api/v1/requests/${requestId}/documents`, documentOptions)))

    const assignmentKey = crypto.randomUUID()
    const assignmentOptions = {
      headers: { 'Idempotency-Key': assignmentKey },
      data: { executorId: 2, lockVersion: 1 },
    }
    expect(await json(await manager.post(`/api/v1/requests/${requestId}/executor`, assignmentOptions)))
      .toEqual(await json(await manager.post(`/api/v1/requests/${requestId}/executor`, assignmentOptions)))

    const transitionKey = crypto.randomUUID()
    const transitionOptions = {
      headers: { 'Idempotency-Key': transitionKey },
      data: { lockVersion: 2 },
    }
    expect(await json(await manager.post(`/api/v1/requests/${requestId}/start`, transitionOptions)))
      .toEqual(await json(await manager.post(`/api/v1/requests/${requestId}/start`, transitionOptions)))

    const details = await json(await initiator.get(`/api/v1/requests/${requestId}`))
    expect(details.item.status).toBe('in_progress')
    expect(details.comments.filter(item => item.body === 'Один комментарий')).toHaveLength(1)
    expect(details.documents.filter(item => item.originalName === 'same.pdf')).toHaveLength(1)

    const conflict = await initiator.post('/api/v1/requests', {
      ...createOptions,
      data: { ...createOptions.data, supplier: 'Другой payload' },
    })
    expect(conflict.status()).toBe(409)
  } finally {
    await initiator.dispose()
    await manager.dispose()
  }
})
