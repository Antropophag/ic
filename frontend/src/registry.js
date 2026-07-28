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
    canAssignExpert: Boolean(Number(item.can_assign_expert)),
    canStart: Boolean(Number(item.can_start)) && item.status === 'registered',
    canComment: Boolean(Number(item.can_comment)),
    canUploadDocument: Boolean(Number(item.can_upload_document)),
    canUploadReport: Boolean(Number(item.can_upload_report)),
    canPublishOpinion: Boolean(Number(item.can_publish_opinion)),
    canSecurityDecide: Boolean(Number(item.can_security_decide)),
    canSetColor: Boolean(Number(item.can_set_color)),
    canReject: Boolean(Number(item.can_reject)),
    canWithdraw: Boolean(Number(item.can_withdraw)),
    color: REQUEST_COLORS.includes(item.color) ? item.color : 'white',
    status: STATUS_LABELS[item.status] || item.status,
    tone: STATUS_TONES[item.status] || 'blue',
  }
}

export function withoutStaleActions(item) {
  return {
    ...item,
    canAssignExecutor: false,
    canAssignExpert: false,
    canPublishOpinion: false,
    canSecurityDecide: false,
    canStart: false,
    canSetColor: false,
    canReject: false,
    canWithdraw: false,
  }
}

const HISTORY_LABELS = {
  create: 'создал(а) заявку',
  import: 'импортировал(а) заявку',
  assign_executor: 'назначил(а) исполнителя',
  assign_expert: 'назначил(а) эксперта',
  start: 'перевёл(а) заявку в работу',
  upload_report: 'загрузил(а) отчёт испытаний',
  publish_opinion: 'опубликовал(а) экспертное заключение',
  security_approve: 'согласовал(а) заключение',
  security_return: 'вернул(а) заявку в работу',
  reject: 'отказал(а) в проведении испытаний',
  withdraw: 'отозвал(а) заявку',
}

export function historyFromApi(item) {
  const description = HISTORY_LABELS[item.action] || item.action
  return {
    id: `${item.kind}-${item.id}`,
    actor: item.actorName,
    description: item.reason ? `${description}: ${item.reason}` : description,
    ruleId: item.ruleId,
    occurredAt: new Date(item.occurredAt).toLocaleString('ru-RU'),
  }
}

export function commentFromApi(item) {
  return {
    id: Number(item.id),
    author: item.authorName,
    body: item.body,
    createdAt: new Date(item.createdAt).toLocaleString('ru-RU'),
  }
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

export function filterRequests(requests, { tab, query, status, currentUser }) {
  const normalizedQuery = query.toLowerCase()
  return requests.filter(item => {
    if (tab === 'active' && !ACTIVE_STATUSES.includes(item.status)) return false
    if (tab === 'mine' && item.initiator !== currentUser) return false
    if (status && item.status !== status) return false
    return Object.values(item).join(' ').toLowerCase().includes(normalizedQuery)
  })
}
