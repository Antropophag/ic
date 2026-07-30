export function requestIdFromLocation(location = window.location) {
  const raw = new URLSearchParams(location.search).get('request')
  return raw && /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null
}

// push:true заводит запись в истории браузера (обычная навигация — открыть/
// закрыть заявку кликом), push:false молча синхронизирует URL без записи:
// первичный диплинк из письма (URL уже соответствует, лишняя запись не
// нужна) и реакция на popstate (запись уже есть — её создал сам браузер).
export function setRequestInUrl(requestId, { push = false, history = window.history, location = window.location } = {}) {
  const url = new URL(location.href)
  if (requestId) url.searchParams.set('request', String(requestId))
  else url.searchParams.delete('request')
  const target = `${url.pathname}${url.search}${url.hash}`
  if (push) history.pushState({}, '', target)
  else history.replaceState({}, '', target)
}

export async function resolveRequestDeepLink(requestId, requests, getRequest) {
  const item = requests.find(request => request.backendId === requestId)
  if (item) return { item, detail: null }

  const detail = await getRequest(requestId)
  return { item: detail.item, detail }
}
