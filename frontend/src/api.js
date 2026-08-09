// Сессия аутентифицируется cookie. State-changing запросы требуют
// CSRF-токен. Токен приходит из ответа
// /api/v1/auth/me или /auth/login и хранится здесь на время сессии SPA.
let csrfToken = ''
const mutationIntents = new Map()
const fileIds = new WeakMap()
let nextFileId = 0
const MUTATION_INTENT_TTL_MS = 24 * 60 * 60 * 1000
const MUTATION_INTENT_LIMIT = 100

export function setCsrfToken(token) {
  const nextToken = token || ''
  if (nextToken !== csrfToken) mutationIntents.clear()
  csrfToken = nextToken
}

export function hasCsrfToken() {
  return csrfToken !== ''
}

function authHeaders() {
  return csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
}

async function request(path, options = {}) {
  if (options.method === 'POST' && path.startsWith('/api/v1/') && !path.startsWith('/api/v1/auth/')) {
    return idempotentRequest(path, options)
  }
  return performRequest(path, options)
}

async function performRequest(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { Accept: 'application/json', ...authHeaders(), ...options.headers },
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    const error = new Error(payload.message || 'Ошибка обращения к серверу')
    error.status = response.status
    error.payload = payload
    throw error
  }
  return payload
}

function newIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return `intent-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
}

function fileIdentity(file) {
  let id = fileIds.get(file)
  if (!id) {
    id = `file-${++nextFileId}`
    fileIds.set(file, id)
  }
  return id
}

function mutationSignature(path, body) {
  if (typeof body === 'string') return `${path}\0${body}`
  if (body instanceof FormData) {
    const fields = []
    for (const [name, value] of body.entries()) {
      fields.push(typeof File !== 'undefined' && value instanceof File
        ? `${name}:${fileIdentity(value)}`
        : `${name}:${value}`)
    }
    return `${path}\0${fields.join('\0')}`
  }
  return path
}

function pruneMutationIntents(now) {
  for (const [signature, intent] of mutationIntents) {
    if (now - intent.createdAt > MUTATION_INTENT_TTL_MS) mutationIntents.delete(signature)
  }
  while (mutationIntents.size >= MUTATION_INTENT_LIMIT) {
    mutationIntents.delete(mutationIntents.keys().next().value)
  }
}

function idempotentRequest(path, options) {
  const signature = mutationSignature(path, options.body)
  const existing = mutationIntents.get(signature)
  if (existing?.promise) return existing.promise

  const now = Date.now()
  pruneMutationIntents(now)
  const intent = existing || { key: newIdempotencyKey(), createdAt: now, promise: null }
  intent.promise = performRequest(path, {
    ...options,
    headers: { ...options.headers, 'Idempotency-Key': intent.key },
  }).then(payload => {
    if (mutationIntents.get(signature) === intent) mutationIntents.delete(signature)
    return payload
  }).catch(error => {
    if (mutationIntents.get(signature) === intent) {
      intent.promise = null
      mutationIntents.set(signature, intent)
    }
    throw error
  })
  mutationIntents.set(signature, intent)
  return intent.promise
}

export const requestApi = {
  events: () => request('/api/v1/requests/events'),
  dashboard: () => request('/api/v1/requests/dashboard'),
  list: (params = {}) => {
    const query = new URLSearchParams()
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) query.set(key, String(value))
    })
    const suffix = query.size ? `?${query}` : ''
    return request(`/api/v1/requests${suffix}`)
  },
  get: requestId => request(`/api/v1/requests/${requestId}`),
  comments: (requestId, beforeId) => request(`/api/v1/requests/${requestId}/comments?beforeId=${beforeId}`),
  addComment: (requestId, body) => request(`/api/v1/requests/${requestId}/comments`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ body }),
  }),
  uploadDocument: (requestId, file) => {
    const body = new FormData()
    body.append('file', file)
    return request(`/api/v1/requests/${requestId}/documents`, { method: 'POST', body })
  },
  uploadReport: (requestId, file) => {
    const body = new FormData()
    body.append('file', file)
    return request(`/api/v1/requests/${requestId}/report`, { method: 'POST', body })
  },
  deleteReport: (requestId, lockVersion, reason) => request(`/api/v1/requests/${requestId}/report/delete`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion, reason }),
  }),
  downloadDocument: async (versionId) => {
    const response = await fetch(`/api/v1/document-versions/${versionId}/download`, {
      headers: authHeaders(),
    })
    if (!response.ok) {
      const error = new Error('Не удалось скачать документ')
      error.status = response.status
      throw error
    }
    return response.blob()
  },
  executors: () => request('/api/v1/executors'),
  experts: () => request('/api/v1/experts'),
  create: (data) => request('/api/v1/requests', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  }),
  assignExecutor: (requestId, executorId, lockVersion) => request(`/api/v1/requests/${requestId}/executor`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ executorId, lockVersion }),
  }),
  claimExpert: (requestId, lockVersion) => request(`/api/v1/requests/${requestId}/expert/claim`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion }),
  }),
  reassignExpert: (requestId, expertId, lockVersion) => request(`/api/v1/requests/${requestId}/expert/reassign`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ expertId, lockVersion }),
  }),
  publishOpinion: (requestId, body, lockVersion) => request(`/api/v1/requests/${requestId}/opinion`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ body, lockVersion }),
  }),
  decideSecurity: (requestId, decision, reason, lockVersion) => request(`/api/v1/requests/${requestId}/security-decision`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ decision, reason, lockVersion }),
  }),
  start: (requestId, lockVersion) => request(`/api/v1/requests/${requestId}/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion }),
  }),
  suspend: (requestId, lockVersion, reason) => request(`/api/v1/requests/${requestId}/suspend`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion, reason }),
  }),
  resume: (requestId, lockVersion) => request(`/api/v1/requests/${requestId}/resume`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion }),
  }),
  setColor: (requestId, color, lockVersion) => request(`/api/v1/requests/${requestId}/color`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ color, lockVersion }),
  }),
  changeDepartment: (requestId, department, lockVersion) => request(`/api/v1/requests/${requestId}/department`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ department, lockVersion }),
  }),
  reject: (requestId, lockVersion, reason) => request(`/api/v1/requests/${requestId}/reject`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion, reason }),
  }),
  withdraw: (requestId, lockVersion, reason) => request(`/api/v1/requests/${requestId}/withdraw`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion, reason }),
  }),
}

export const authApi = {
  me: () => request('/api/v1/auth/me'),
  login: (login, password) => request('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login, password }),
  }),
  logout: () => request('/api/v1/auth/logout', { method: 'POST' }),
}

export const devApi = {
  seedRequests: () => request('/api/v1/dev/seed-requests', { method: 'POST' }),
  reviewFeedback: signal => request('/api/v1/dev/review-feedback', { signal }),
  createReviewFeedback: (body, checklist, signal) => request('/api/v1/dev/review-feedback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ body, checklist }),
    signal,
  }),
}

export const adminApi = {
  users: () => request('/api/v1/admin/users'),
  roles: () => request('/api/v1/admin/roles'),
  auditEvents: params => request(`/api/v1/admin/audit-events${queryString(params)}`),
  notifications: params => request(`/api/v1/admin/notifications${queryString(params)}`),
  createUser: adLogin => request('/api/v1/admin/users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ adLogin }),
  }),
  assignRole: (userId, roleId) => request(`/api/v1/admin/users/${userId}/roles`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ roleId }),
  }),
  revokeRole: (userId, roleId, reason) => request(`/api/v1/admin/users/${userId}/roles/${roleId}/revoke`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ reason }),
  }),
}

function queryString(params = {}) {
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (typeof value === 'boolean') query.set(key, value ? '1' : '0')
    else if (['string', 'number'].includes(typeof value) && value !== '') query.set(key, String(value))
  })
  const serialized = query.toString()
  return serialized ? `?${serialized}` : ''
}
