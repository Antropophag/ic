import { afterEach, expect, it, vi } from 'vitest'
import { adminApi, authApi, hasCsrfToken, requestApi, setCsrfToken } from './api'

afterEach(() => {
  vi.unstubAllGlobals()
  setCsrfToken('')
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

it('sends registry pagination, filters, search, and sorting', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ items: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.list({ page: 3, pageSize: 10, tab: 'mine', status: 'completed', query: 'насос', sort: 'asc' })

  expect(fetchMock).toHaveBeenCalledWith(
    '/api/v1/requests?page=3&pageSize=10&tab=mine&status=completed&query=%D0%BD%D0%B0%D1%81%D0%BE%D1%81&sort=asc',
    expect.any(Object),
  )
})

it('loads dashboard counts and sends an attention queue filter', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ categories: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.dashboard()
  await requestApi.list({ page: 1, attention: 'publish_opinion' })
  await requestApi.list({ page: 1, attention: '' })

  expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/requests/dashboard')
  expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/requests?page=1&attention=publish_opinion')
  expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/requests?page=1')
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
    headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
  }))
})

it('coalesces a double click into one POST with one idempotency key', async () => {
  let resolveFetch
  const fetchMock = vi.fn().mockImplementation(() => new Promise(resolve => { resolveFetch = resolve }))
  vi.stubGlobal('fetch', fetchMock)

  const first = requestApi.addComment(17, 'Один комментарий')
  const second = requestApi.addComment(17, 'Один комментарий')
  expect(fetchMock).toHaveBeenCalledTimes(1)
  resolveFetch(new Response('{"id":1}', { status: 201 }))

  await expect(Promise.all([first, second])).resolves.toEqual([{ id: 1 }, { id: 1 }])
})

it('reuses the idempotency key when retrying after a server error', async () => {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response('{"message":"failed"}', { status: 500 }))
    .mockResolvedValueOnce(new Response('{"id":2}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)

  await expect(requestApi.addComment(18, 'Retry')).rejects.toMatchObject({ status: 500 })
  await expect(requestApi.addComment(18, 'Retry')).resolves.toEqual({ id: 2 })

  const firstKey = fetchMock.mock.calls[0][1].headers['Idempotency-Key']
  const secondKey = fetchMock.mock.calls[1][1].headers['Idempotency-Key']
  expect(firstKey).toBe(secondKey)
})

it('uses a new key for a new identical intent after success', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{"id":1}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.addComment(19, 'Intent')
  await requestApi.addComment(19, 'Intent')

  expect(fetchMock.mock.calls[0][1].headers['Idempotency-Key'])
    .not.toBe(fetchMock.mock.calls[1][1].headers['Idempotency-Key'])
})

it('does not carry a failed intent into another authenticated session', async () => {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response('{"message":"failed"}', { status: 500 }))
    .mockResolvedValueOnce(new Response('{"id":2}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)
  setCsrfToken('first-session')

  await expect(requestApi.addComment(20, 'Session intent')).rejects.toMatchObject({ status: 500 })
  setCsrfToken('second-session')
  await requestApi.addComment(20, 'Session intent')

  expect(fetchMock.mock.calls[0][1].headers['Idempotency-Key'])
    .not.toBe(fetchMock.mock.calls[1][1].headers['Idempotency-Key'])
})

it('does not let a completed stale session intent replace the current pending intent', async () => {
  const resolvers = []
  const fetchMock = vi.fn().mockImplementation(() => new Promise(resolve => resolvers.push(resolve)))
  vi.stubGlobal('fetch', fetchMock)
  setCsrfToken('first-session')

  const stale = requestApi.addComment(21, 'Session race')
  setCsrfToken('second-session')
  const current = requestApi.addComment(21, 'Session race')
  resolvers[0](new Response('{"id":1}', { status: 201 }))
  await stale

  const coalesced = requestApi.addComment(21, 'Session race')
  expect(fetchMock).toHaveBeenCalledTimes(2)
  resolvers[1](new Response('{"id":2}', { status: 201 }))
  await expect(Promise.all([current, coalesced])).resolves.toEqual([{ id: 2 }, { id: 2 }])
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

it('does not coalesce different files that have identical metadata', async () => {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response('{"id":1}', { status: 201 }))
    .mockResolvedValueOnce(new Response('{"id":2}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)
  const options = { type: 'application/pdf', lastModified: 123 }
  const first = new File(['one'], 'same.pdf', options)
  const second = new File(['two'], 'same.pdf', options)

  await expect(Promise.all([
    requestApi.uploadDocument(7, first),
    requestApi.uploadDocument(7, second),
  ])).resolves.toEqual([{ id: 1 }, { id: 2 }])
  expect(fetchMock).toHaveBeenCalledTimes(2)
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

it('changes a request department with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.changeDepartment(7, 'Подразделение C', 3)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/department', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ department: 'Подразделение C', lockVersion: 3 }),
  }))
})

it('claims an expert opinion task with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValueOnce(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.claimExpert(7, 5)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/expert/claim', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 5 }),
  }))
})

it('loads and reassigns an expert with optimistic locking', async () => {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200 }))
    .mockResolvedValueOnce(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.experts()
  await requestApi.reassignExpert(7, 4, 5)

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/experts', expect.any(Object))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/requests/7/expert/reassign', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ expertId: 4, lockVersion: 5 }),
  }))
})

it('publishes an opinion with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.publishOpinion(7, 'Испытания пройдены успешно.', 6)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/opinion', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ body: 'Испытания пройдены успешно.', lockVersion: 6 }),
  }))
})

it('sends a security decision with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.decideSecurity(7, 'return', 'Нужно уточнить вывод.', 7)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/security-decision', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ decision: 'return', reason: 'Нужно уточнить вывод.', lockVersion: 7 }),
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

it('sets the manual color mark with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.setColor(7, 'red', 3)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/color', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ color: 'red', lockVersion: 3 }),
  }))
})

it('rejects a request with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.reject(7, 2)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/reject', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 2 }),
  }))
})

it('withdraws a request with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.withdraw(7, 2)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/withdraw', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 2 }),
  }))
})

it('withdraws a request with an optional reason', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.withdraw(7, 2, 'Заявка подана повторно')

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/withdraw', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 2, reason: 'Заявка подана повторно' }),
  }))
})

it('suspends and resumes a request with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.suspend(7, 2)
  await requestApi.resume(7, 3)

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/requests/7/suspend', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 2 }),
  }))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/requests/7/resume', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 3 }),
  }))
})

it('deletes a report with optimistic locking', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.deleteReport(7, 2)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests/7/report/delete', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ lockVersion: 2 }),
  }))
})

it('omits the CSRF header until a token is set', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ items: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await requestApi.list()

  const headers = fetchMock.mock.calls[0][1].headers
  expect(headers).not.toHaveProperty('X-CSRF-Token')
})

it('sends the CSRF token once set, on every request', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ items: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)
  setCsrfToken('token-value')

  await requestApi.list()

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/requests', expect.objectContaining({
    headers: expect.objectContaining({ 'X-CSRF-Token': 'token-value' }),
  }))
})

it('reports whether a CSRF token is currently set', () => {
  expect(hasCsrfToken()).toBe(false)

  setCsrfToken('token-value')
  expect(hasCsrfToken()).toBe(true)

  setCsrfToken('')
  expect(hasCsrfToken()).toBe(false)
})

it('fetches the current session and stores its csrf token', async () => {
  const payload = { csrfToken: 'abc', user: { id: 1, displayName: 'Иван Иванов' } }
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(payload), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await expect(authApi.me()).resolves.toEqual(payload)
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/me', expect.any(Object))
})

it('logs in with login and password as JSON', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await authApi.login('ivanov', 'secret')

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/login', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ login: 'ivanov', password: 'secret' }),
  }))
})

it('logs out with a plain POST', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await authApi.logout()

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/logout', expect.objectContaining({ method: 'POST' }))
})

it('lists admin users and roles', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ items: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await adminApi.users()
  await adminApi.roles()

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/users', expect.any(Object))
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/roles', expect.any(Object))
})

it('lists read-only admin logs with shared safe query serialization', async () => {
  const response = () => new Response(JSON.stringify({ items: [] }), { status: 200 })
  const fetchMock = vi.fn().mockImplementation(response)
  vi.stubGlobal('fetch', fetchMock)

  await adminApi.auditEvents({ actorId: 7, result: 'denied', cursor: 'abc', empty: '' })
  await adminApi.notifications({ status: 'failed', requestId: 42 })

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/admin/audit-events?actorId=7&result=denied&cursor=abc', expect.any(Object))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/admin/notifications?status=failed&requestId=42', expect.any(Object))
})

it('serializes admin boolean query values as backend-compatible integers', async () => {
  const response = () => new Response(JSON.stringify({ items: [] }), { status: 200 })
  const fetchMock = vi.fn().mockImplementation(response)
  vi.stubGlobal('fetch', fetchMock)

  await adminApi.notifications({ problematic: true })
  await adminApi.notifications({ problematic: false })
  await adminApi.notifications()
  await adminApi.notifications({ problematic: '' })

  expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/v1/admin/notifications?problematic=1', expect.any(Object))
  expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/admin/notifications?problematic=0', expect.any(Object))
  expect(fetchMock).toHaveBeenNthCalledWith(3, '/api/v1/admin/notifications', expect.any(Object))
  expect(fetchMock).toHaveBeenNthCalledWith(4, '/api/v1/admin/notifications', expect.any(Object))
})

it('omits unsupported admin query values', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ items: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await adminApi.auditEvents({ actorId: 7, object: { secret: true }, array: ['denied'] })

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/audit-events?actorId=7', expect.any(Object))
})

it('creates a pre-provisioned admin user as JSON', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }))
  vi.stubGlobal('fetch', fetchMock)

  await adminApi.createUser('kashin')

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/users', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ adLogin: 'kashin' }),
  }))
})

it('assigns and revokes a role for a user', async () => {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ items: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)

  await adminApi.assignRole(7, 4)
  await adminApi.revokeRole(7, 4)

  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/users/7/roles', expect.objectContaining({
    method: 'POST',
    body: JSON.stringify({ roleId: 4 }),
  }))
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/users/7/roles/4/revoke', expect.objectContaining({
    method: 'POST',
  }))
})
