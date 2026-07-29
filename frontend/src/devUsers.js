const STORAGE_KEY = 'shlz-dev-user-id'

export const DEV_USERS = [
  { id: 1, displayName: 'Максим Умнов', position: 'Руководитель ИЦ', department: 'Испытательный центр' },
  { id: 2, displayName: 'Сергей Кашин', position: 'Исполнитель ИЦ', department: 'Испытательный центр' },
  { id: 3, displayName: 'Тестовый сотрудник', position: 'Сотрудник', department: 'Тестовое подразделение' },
  { id: 4, displayName: 'Анна Смирнова', position: 'Эксперт', department: 'Испытательный центр' },
  { id: 5, displayName: 'Олег Воронцов', position: 'Сотрудник СБ', department: 'Служба безопасности' },
]

let overrideId = null

function readStoredId() {
  try {
    if (typeof localStorage === 'undefined') return NaN
    return Number(localStorage.getItem(STORAGE_KEY))
  } catch {
    return NaN
  }
}

function persistId(id) {
  try {
    if (typeof localStorage === 'undefined') return
    localStorage.setItem(STORAGE_KEY, String(id))
  } catch {
    // Персистентность необязательна для dev-переключателя: недоступное
    // хранилище не должно прерывать смену актёра в памяти.
  }
}

export function getDevUserId() {
  if (overrideId !== null) return overrideId
  const stored = readStoredId()
  return DEV_USERS.some(user => user.id === stored) ? stored : DEV_USERS[0].id
}

export function getDevUser() {
  const id = getDevUserId()
  return DEV_USERS.find(user => user.id === id) ?? DEV_USERS[0]
}

export function setDevUserId(id) {
  if (!DEV_USERS.some(user => user.id === id)) return
  overrideId = id
  persistId(id)
}
