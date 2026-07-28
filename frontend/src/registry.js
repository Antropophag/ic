export const ACTIVE_STATUSES = [
  'Заявка зарегистрирована',
  'Заявка в работе',
  'Работы приостановлены',
  'Подготовка заключения',
  'Контроль СБ',
]

const STATUS_LABELS = {
  registered: 'Заявка зарегистрирована',
}

export function fromApi(item) {
  return {
    id: String(item.number).padStart(6, '0'),
    date: new Date(item.created_at).toLocaleDateString('ru-RU'),
    initiator: item.initiator_name,
    department: item.department,
    product: item.product_name,
    manufacturer: item.manufacturer,
    supplier: item.supplier,
    sampleQuantity: item.sample_quantity,
    testMethod: item.test_method,
    executor: 'Не назначен',
    status: STATUS_LABELS[item.status] || item.status,
    tone: 'blue',
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
