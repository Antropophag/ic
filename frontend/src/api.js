import { getDevUserId } from './devUsers'

function devHeaders() {
  return { 'X-Dev-User-ID': String(getDevUserId()) }
}

async function request(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { Accept: 'application/json', ...devHeaders(), ...options.headers },
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
  list: () => request('/api/v1/requests'),
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
  downloadDocument: async (versionId) => {
    const response = await fetch(`/api/v1/document-versions/${versionId}/download`, {
      headers: { ...devHeaders() },
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
  assignExpert: (requestId, expertId, lockVersion) => request(`/api/v1/requests/${requestId}/expert`, {
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
  setColor: (requestId, color, lockVersion) => request(`/api/v1/requests/${requestId}/color`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ color, lockVersion }),
  }),
}
