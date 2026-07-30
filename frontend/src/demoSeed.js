export async function runDemoSeed(seed, onSeeded, refresh) {
  let result
  try {
    result = await seed()
  } catch {
    return 'Не удалось заполнить демо-данные.'
  }

  onSeeded()
  const successMessage = `Создано демо-заявок: ${result.requests}.`
  try {
    await refresh()
    return successMessage
  } catch {
    return `${successMessage} Не удалось обновить список — обновите страницу.`
  }
}
