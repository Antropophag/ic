export async function confirmRequestAction(getSelected, ask) {
  const selected = getSelected()
  if (!selected) return null

  const requestId = selected.backendId
  const lockVersion = selected.lockVersion
  const confirmed = await ask()

  if (!confirmed || getSelected()?.backendId !== requestId) return null

  return { requestId, lockVersion, confirmed }
}
