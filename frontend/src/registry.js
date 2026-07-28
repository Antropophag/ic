export const ACTIVE_STATUSES = [
  'Заявка зарегистрирована',
  'Заявка в работе',
  'Работы приостановлены',
  'Подготовка заключения',
  'Контроль СБ',
]

const STATUS_LABELS = {
  registered: 'Заявка зарегистрирована',
  in_progress: 'Заявка в работе',
}

const STATUS_TONES = { registered: 'blue', in_progress: 'cyan' }

export function fromApi(item) {
  return {
    backendId: Number(item.id),
    id: String(item.number).padStart(6, '0'),
    date: new Date(item.created_at).toLocaleDateString('ru-RU'),
    initiator: item.initiator_name,
    department: item.department,
    product: item.product_name,
    manufacturer: item.manufacturer,
    supplier: item.supplier,
    sampleQuantity: item.sample_quantity,
    testMethod: item.test_method,
    executor: item.executor_name || 'Не назначен',
    executorId: item.executor_id ? Number(item.executor_id) : null,
    lockVersion: Number(item.lockVersion),
    canAssignExecutor: Boolean(Number(item.can_assign_executor)),
    canStart: Boolean(Number(item.can_start)) && item.status === 'registered',
    status: STATUS_LABELS[item.status] || item.status,
    tone: STATUS_TONES[item.status] || 'blue',
  }
}

const HISTORY_LABELS = {
  create: 'создал(а) заявку',
  import: 'импортировал(а) заявку',
  assign_executor: 'назначил(а) исполнителя',
  start: 'перевёл(а) заявку в работу',
}

export function historyFromApi(item) {
  return {
    id: `${item.kind}-${item.id}`,
    actor: item.actorName,
    description: HISTORY_LABELS[item.action] || item.action,
    ruleId: item.ruleId,
    occurredAt: new Date(item.occurredAt).toLocaleString('ru-RU'),
  }
}

export function filterRequests(requests, { tab, query, status, currentUser }) {
  const normalizedQuery = query.toLowerCase()
  return requests.filter(item => {
    if (tab === 'active' && !ACTIVE_STATUSES.includes(item.status)) return false
    if (tab === 'mine' && item.initiator !== currentUser) return false
    if (status && item.status !== status) return false
    return Object.values(item).join(' ').toLowerCase().includes(normalizedQuery)
  })
}
