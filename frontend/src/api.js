// Сессия аутентифицируется cookie. State-changing запросы требуют
// CSRF-токен. Токен приходит из ответа
// /api/v1/auth/me или /auth/login и хранится здесь на время сессии SPA.
let csrfToken = ''

export function setCsrfToken(token) {
  csrfToken = token || ''
}

export function hasCsrfToken() {
  return csrfToken !== ''
}

function authHeaders() {
  return csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
}

async function request(path, options = {}) {
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

export const requestApi = {
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
  deleteReport: (requestId, lockVersion) => request(`/api/v1/requests/${requestId}/report/delete`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion }),
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
  suspend: (requestId, lockVersion) => request(`/api/v1/requests/${requestId}/suspend`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion }),
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
  revokeRole: (userId, roleId) => request(`/api/v1/admin/users/${userId}/roles/${roleId}/revoke`, {
    method: 'POST',
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
