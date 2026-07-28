const DEV_HEADERS = import.meta.env.VITE_DEV_USER_ID
  ? { 'X-Dev-User-ID': import.meta.env.VITE_DEV_USER_ID }
  : {}

async function request(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { Accept: 'application/json', ...DEV_HEADERS, ...options.headers },
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
  executors: () => request('/api/v1/executors'),
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
  start: (requestId, lockVersion) => request(`/api/v1/requests/${requestId}/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lockVersion }),
  }),
}
