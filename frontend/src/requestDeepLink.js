export function requestIdFromLocation(location = window.location) {
  const raw = new URLSearchParams(location.search).get('request')
  return raw && /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null
}

export function setRequestInUrl(requestId, history = window.history, location = window.location) {
  const url = new URL(location.href)
  if (requestId) url.searchParams.set('request', String(requestId))
  else url.searchParams.delete('request')
  history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`)
}
