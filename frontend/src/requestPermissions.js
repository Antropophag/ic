const REQUEST_CREATION_FORBIDDEN_ROLES = new Set([
  'ic_executor',
  'ic_manager',
  'laboratory_manager',
])

/** Возвращает право показывать личный реестр и форму создания заявки. */
export function canCreateRequest(roles) {
  return !roles.some(role => REQUEST_CREATION_FORBIDDEN_ROLES.has(role))
}
