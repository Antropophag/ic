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
  create: (data) => request('/api/v1/requests', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  }),
}
