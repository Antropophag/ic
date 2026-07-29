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
  suspended: 'Работы приостановлены',
  opinion_preparation: 'Подготовка заключения',
  security_review: 'Контроль СБ',
  completed: 'Заявка выполнена',
  rejected: 'В проведении испытаний отказано',
  withdrawn: 'Заявка отозвана',
}

const STATUS_TONES = {
  registered: 'blue', in_progress: 'cyan', suspended: 'orange',
  opinion_preparation: 'violet', security_review: 'yellow', completed: 'green',
}

export const REQUEST_COLORS = ['white', 'red', 'orange', 'blue', 'violet', 'green']

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
    expert: item.expert_name || 'Не назначен',
    expertId: item.expert_id ? Number(item.expert_id) : null,
    lockVersion: Number(item.lockVersion),
    canAssignExecutor: Boolean(Number(item.can_assign_executor)),
    canClaimExpert: Boolean(Number(item.can_claim_expert)),
    canReassignExpert: Boolean(Number(item.can_reassign_expert)),
    canStart: Boolean(Number(item.can_start)) && item.status === 'registered',
    canComment: Boolean(Number(item.can_comment)),
    canUploadDocument: Boolean(Number(item.can_upload_document)),
    canUploadReport: Boolean(Number(item.can_upload_report)),
    canDeleteReport: Boolean(Number(item.can_delete_report)),
    canPublishOpinion: Boolean(Number(item.can_publish_opinion)),
    canSecurityDecide: Boolean(Number(item.can_security_decide)),
    canSetColor: Boolean(Number(item.can_set_color)),
    canReject: Boolean(Number(item.can_reject)),
    canWithdraw: Boolean(Number(item.can_withdraw)),
    color: REQUEST_COLORS.includes(item.color) ? item.color : 'white',
    status: STATUS_LABELS[item.status] || item.status,
    tone: STATUS_TONES[item.status] || 'blue',
    securityMark: item.security_mark === 'approve' ? '✓' : item.security_mark === 'return' ? '✕' : '—',
  }
}

export function withoutStaleActions(item) {
  return {
    ...item,
    canAssignExecutor: false,
    canClaimExpert: false,
    canReassignExpert: false,
    canPublishOpinion: false,
    canSecurityDecide: false,
    canStart: false,
    canSetColor: false,
    canReject: false,
    canWithdraw: false,
    canDeleteReport: false,
  }
}

const HISTORY_LABELS = {
  create: 'создал(а) заявку',
  import: 'импортировал(а) заявку',
  assign_executor: 'назначил(а) исполнителя',
  claim_expert: 'взял(а) заявку в работу (эксперт)',
  reassign_expert: 'переназначил(а) эксперта',
  start: 'перевёл(а) заявку в работу',
  upload_report: 'загрузил(а) отчёт испытаний',
  delete_report: 'удалил(а) отчёт испытаний',
  publish_opinion: 'опубликовал(а) экспертное заключение',
  security_approve: 'согласовал(а) заключение',
  security_return: 'вернул(а) заявку в работу',
  reject: 'отказал(а) в проведении испытаний',
  withdraw: 'отозвал(а) заявку',
}

export function historyFromApi(item) {
  const description = HISTORY_LABELS[item.action] || item.action
  return {
    type: 'milestone',
    id: `${item.kind}-${item.id}`,
    actor: item.actorName,
    description: item.reason ? `${description}: ${item.reason}` : description,
    ruleId: item.ruleId,
    occurredAt: new Date(item.occurredAt).toLocaleString('ru-RU'),
    sortAt: item.occurredAt,
  }
}

export function commentFromApi(item) {
  return {
    type: 'comment',
    id: Number(item.id),
    author: item.authorName,
    body: item.body,
    createdAt: new Date(item.createdAt).toLocaleString('ru-RU'),
    sortAt: item.createdAt,
  }
}

// Единая хронологическая лента заявки (переходы статуса вперемешку с
// обсуждением, как в таймлайне GitHub PR) — заменяет разрозненные вкладку
// «Обсуждение» и отдельную модалку «История»: одна история вместо двух.
// Видимость документов (DOC-003/ACL-002) лента не затрагивает — она
// показывает только сам факт события, а не содержимое документа.
//
// Backend отдаёт оба источника в порядке «новые сначала» (ORDER BY ... DESC —
// удобно для постраничной подгрузки старых записей), а лента должна читаться
// по возрастанию времени, как переписка. Array.prototype.sort стабилен, но
// секундная точность occurredAt/createdAt означает, что несколько событий
// подряд (например, быстрая смена статусов) могут получить одинаковый
// sortAt — без предварительного разворота каждого источника стабильная
// сортировка сохранила бы их неверный DESC-порядок внутри одной секунды.
export function buildFeed(history, comments) {
  const orderedHistory = [...history].reverse()
  const orderedComments = [...comments].reverse()
  return [...orderedHistory, ...orderedComments].sort((a, b) => new Date(a.sortAt) - new Date(b.sortAt))
}

export function canSubmitComment(item, detailLoading) {
  return Boolean(item?.canComment) && !detailLoading
}

export function documentFromApi(item) {
  return {
    id: Number(item.id),
    title: item.title,
    documentType: item.documentType || 'attachment',
    versionId: Number(item.versionId),
    version: Number(item.version),
    originalName: item.originalName,
    mimeType: item.mimeType,
    size: `${Math.max(1, Math.ceil(Number(item.sizeBytes) / 1024))} КБ`,
    sha256: item.sha256,
    uploadedBy: item.uploadedBy,
    createdAt: new Date(item.createdAt).toLocaleString('ru-RU'),
  }
}

export function filterRequests(requests, { tab, query, status, currentUser, sortDirection = 'desc' }) {
  const normalizedQuery = query.toLowerCase()
  const filtered = requests.filter(item => {
    if (tab === 'active' && !ACTIVE_STATUSES.includes(item.status)) return false
    if (tab === 'mine' && item.initiator !== currentUser) return false
    if (status && item.status !== status) return false
    return Object.values(item).join(' ').toLowerCase().includes(normalizedQuery)
  })
  const direction = sortDirection === 'asc' ? 1 : -1
  return [...filtered].sort((a, b) => direction * (a.backendId - b.backendId))
}

export const REGISTRY_PAGE_SIZE = 10

export function paginate(items, page, pageSize = REGISTRY_PAGE_SIZE) {
  const pageCount = Math.max(1, Math.ceil(items.length / pageSize))
  const safePage = Math.min(Math.max(1, page), pageCount)
  const start = (safePage - 1) * pageSize
  return { items: items.slice(start, start + pageSize), page: safePage, pageCount, total: items.length }
}
