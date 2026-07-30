export async function runDemoSeed(seed, onSeeded, refresh, isCurrent = () => true) {
  let result
  try {
    result = await seed()
  } catch {
    return isCurrent() ? 'Не удалось заполнить демо-данные.' : null
  }
  if (!isCurrent()) return null

  onSeeded()
  const successMessage = `Создано демо-заявок: ${result.requests}.`
  try {
    await refresh()
    return isCurrent() ? successMessage : null
  } catch {
    return isCurrent()
      ? `${successMessage} Не удалось обновить список — обновите страницу.`
      : null
  }
}

export function clearDemoRegistry(requests, registryPage) {
  requests.value = []
  registryPage.total = 0
  registryPage.page = 1
  registryPage.pageCount = 1
  registryPage.counts = { active: 0, all: 0, mine: 0 }
}
