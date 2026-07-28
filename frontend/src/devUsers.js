const STORAGE_KEY = 'shlz-dev-user-id'

export const DEV_USERS = [
  { id: 1, displayName: 'Максим Умнов', position: 'Руководитель ИЦ', department: 'Бюро приводной техники' },
  { id: 2, displayName: 'Сергей Кашин', position: 'Исполнитель ИЦ', department: 'Испытательный центр' },
  { id: 3, displayName: 'Тестовый сотрудник', position: 'Сотрудник', department: 'Тестовое подразделение' },
  { id: 4, displayName: 'Анна Смирнова', position: 'Эксперт', department: 'Испытательный центр' },
  { id: 5, displayName: 'Олег Воронцов', position: 'Сотрудник СБ', department: 'Служба безопасности' },
]

let overrideId = null

function readStorage() {
  try {
    return typeof localStorage === 'undefined' ? null : localStorage
  } catch {
    return null
  }
}

export function getDevUserId() {
  if (overrideId !== null) return overrideId
  const storage = readStorage()
  const stored = storage ? Number(storage.getItem(STORAGE_KEY)) : NaN
  return DEV_USERS.some(user => user.id === stored) ? stored : DEV_USERS[0].id
}

export function getDevUser() {
  const id = getDevUserId()
  return DEV_USERS.find(user => user.id === id) ?? DEV_USERS[0]
}

export function setDevUserId(id) {
  if (!DEV_USERS.some(user => user.id === id)) return
  overrideId = id
  readStorage()?.setItem(STORAGE_KEY, String(id))
}
