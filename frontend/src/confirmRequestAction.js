export async function confirmRequestAction(getSelected, ask) {
  const selected = getSelected()
  if (!selected) return null

  const requestId = selected.backendId
  const lockVersion = selected.lockVersion
  const confirmed = await ask()
  const current = getSelected()

  if (!confirmed || current?.backendId !== requestId || current.lockVersion !== lockVersion) return null

  return { requestId, lockVersion, confirmed }
}
