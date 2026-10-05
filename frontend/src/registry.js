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

const COMPACT_STATUS_LABELS = {
  rejected: 'Отказано',
}

export const REQUEST_STATUS_OPTIONS = Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))

export const REQUEST_COLORS = ['white', 'orange', 'blue', 'violet', 'red', 'green']

const TESTING_DIRECTIONS = {
  white: 'Без направления', red: 'Резерв 1', orange: 'Метрологические испытания',
  blue: 'Механические испытания', violet: 'Электротехнические испытания', green: 'Резерв 2',
}

/** Display names preserve the API's existing color codes and permissions. */
export function testingDirectionLabel(color) {
  return TESTING_DIRECTIONS[color] || 'Направление не определено'
}

export function initialsFor(displayName) {
  return (displayName || '').split(' ').filter(Boolean).map(part => part[0]).join('').slice(0, 2).toUpperCase() || '?'
}

const AVATAR_ROLE_CLASSES = new Set([
  'employee', 'ic_executor', 'expert', 'ic_manager', 'laboratory_manager', 'security_officer', 'administrator',
])

export function avatarRoleClass(role) {
  const normalizedRole = AVATAR_ROLE_CLASSES.has(role) ? role : 'employee'
  return `avatar-role--${normalizedRole.replaceAll('_', '-')}`
}

export function fromApi(item) {
  const securityMark = ['approve', 'decline'].includes(item.security_mark) ? item.security_mark : null
  return {
    backendId: Number(item.id),
    isArchived: Boolean(Number(item.is_archived)),
    source: item.source || 'local',
    id: String(item.number).padStart(6, '0'),
    date: new Date(item.created_at).toLocaleDateString('ru-RU'),
    initiator: item.initiator_name,
    initiatorPosition: item.initiator_position,
    department: item.department,
    product: item.product_name,
    objectTooltip: (item.object_names?.length ? item.object_names : [item.product_name]).join('\n'),
    objectCount: Number(item.object_count || item.objects?.length || 1),
    objects: Array.isArray(item.objects) ? item.objects.map(object => ({
      name: object.productName,
      sampleQuantityText: object.sampleQuantity || '',
    })) : [{ name: item.product_name, sampleQuantityText: item.sample_quantity ? `${item.sample_quantity} шт.` : item.legacy_sample_quantity_raw || '' }],
    manufacturer: item.manufacturer,
    supplier: item.supplier,
    sampleQuantity: item.sample_quantity,
    legacySampleQuantityRaw: item.legacy_sample_quantity_raw,
    testMethod: item.test_method,
    executor: item.executor_name || 'Не назначен',
    executorId: item.executor_id ? Number(item.executor_id) : null,
    expert: item.expert_name || 'Не назначен',
    expertId: item.expert_id ? Number(item.expert_id) : null,
    lockVersion: Number(item.lockVersion),
    route: item.route || null,
    routeLabel: item.route === 'act' ? 'Акт испытаний' : item.route === 'protocol' ? 'Протокол испытаний' : 'Не выбран',
    canChooseRoute: Boolean(Number(item.can_choose_route)),
    canCompleteAct: Boolean(Number(item.can_complete_act)),
    canAssignExecutor: Boolean(Number(item.can_assign_executor)),
    canClaimExpert: Boolean(Number(item.can_claim_expert)),
    canReassignExpert: Boolean(Number(item.can_reassign_expert)),
    canStart: Boolean(Number(item.can_start)) && item.status === 'registered',
    canSuspend: Boolean(Number(item.can_suspend)),
    canResume: Boolean(Number(item.can_resume)),
    canComment: Boolean(Number(item.can_comment)),
    canUploadDocument: Boolean(Number(item.can_upload_document)),
    canUploadReport: Boolean(Number(item.can_upload_report)),
    canDeleteReport: Boolean(Number(item.can_delete_report)),
    canPublishOpinion: Boolean(Number(item.can_publish_opinion)),
    canSecurityDecide: Boolean(Number(item.can_security_decide)),
    canCorrectSecurityDecision: Boolean(Number(item.can_correct_security_decision)),
    canSetColor: Boolean(Number(item.can_set_color)),
    canEditDepartment: Boolean(Number(item.can_edit_department)),
    canReject: Boolean(Number(item.can_reject)),
    canWithdraw: Boolean(Number(item.can_withdraw)),
    color: REQUEST_COLORS.includes(item.color) ? item.color : 'white',
    colorValue: item.color ?? 'white',
    directionLabel: testingDirectionLabel(item.color ?? 'white'),
    status: STATUS_LABELS[item.status] || item.status,
    compactStatus: COMPACT_STATUS_LABELS[item.status] || STATUS_LABELS[item.status] || item.status,
    securityMark,
    // Вычисляется один раз при маппинге, а не при каждом обращении к
    // className/label/path в шаблоне (реестр рендерит это на каждую
    // строку) — тот же {className,label,path}, что вернул бы прямой
    // вызов securityMarkIcon(securityMark) (Qodo).
    securityMarkDisplay: securityMarkIcon(securityMark),
    lastCommentAuthor: item.last_comment_author || null,
    lastCommentBody: item.last_comment_body || null,
    lastCommentAt: item.last_comment_created_at ? new Date(item.last_comment_created_at).toLocaleString('ru-RU') : null,
    hasReport: Boolean(Number(item.has_report)),
    reportVersionId: item.report_version_id ? Number(item.report_version_id) : null,
    reportOriginalName: item.report_original_name || null,
  }
}

export function sampleQuantityDisplay(request) {
  if (Number.isSafeInteger(request?.sampleQuantity) && request.sampleQuantity >= 1) {
    return `${request.sampleQuantity} шт.`
  }
  if (request?.isArchived && typeof request.legacySampleQuantityRaw === 'string' && request.legacySampleQuantityRaw.trim() !== '') {
    return request.legacySampleQuantityRaw
  }
  return '—'
}

export function withoutStaleActions(item) {
  return {
    ...item,
    canChooseRoute: false,
    canCompleteAct: false,
    canAssignExecutor: false,
    canClaimExpert: false,
    canReassignExpert: false,
    canPublishOpinion: false,
    canSecurityDecide: false,
    canCorrectSecurityDecision: false,
    canStart: false,
    canSuspend: false,
    canResume: false,
    canSetColor: false,
    canReject: false,
    canWithdraw: false,
    canDeleteReport: false,
    canEditDepartment: false,
  }
}

const HISTORY_LABELS = {
  create: 'Заявка создана',
  import: 'Заявка импортирована',
  assign_executor: 'Исполнитель назначен',
  claim_expert: 'Эксперт взял заявку в работу',
  reassign_expert: 'Эксперт переназначен',
  start: 'Заявка переведена в работу',
  suspend: 'Работа по заявке приостановлена',
  resume: 'Работа по заявке возобновлена',
  upload_report: 'Отчёт испытаний загружен',
  delete_report: 'Отчёт испытаний удалён',
  publish_opinion: 'Экспертное заключение опубликовано',
  choose_route: 'Маршрут выбран',
  complete_act: 'Акт испытаний завершён',
  security_approve: 'СБ: согласовано, заявка выполнена',
  security_decline: 'СБ: не согласовано, заявка выполнена',
  correct_security_decision: 'Исправлено решение СБ',
  reject: 'В проведении испытаний отказано',
  withdraw: 'Заявка отозвана',
  change_department: 'Подразделение заявки изменено',
}

// Действия с явным адресатом (кого назначили) — для остальных targetName
// либо отсутствует (backend отдаёт null), либо совпадает с автором действия
// (claim_expert — эксперт берёт заявку себе, уточнение излишне).
const ACTIONS_WITH_TARGET = new Set(['assign_executor', 'reassign_expert'])

export function historyFromApi(item) {
  const description = HISTORY_LABELS[item.action] || item.action
  // В ленте display_name остаётся в исходном именительном падеже
  // без риска грамматической ошибки — имя добавляется через двоеточие, тем
  // же приёмом, что и комментарий к решению СБ, а не согласованием окончаний.
  const qualifier = item.reason || (ACTIONS_WITH_TARGET.has(item.action) || ['change_department', 'choose_route'].includes(item.action) ? item.targetName : '')
  return {
    type: 'milestone',
    id: `${item.kind}-${item.id}`,
    action: item.action,
    actor: item.actorName,
    description: qualifier ? `${description}: ${qualifier}` : description,
    ruleId: item.ruleId,
    occurredAt: new Date(item.occurredAt).toLocaleString('ru-RU'),
    sortAt: item.occurredAt,
    versionId: item.versionId == null ? null : Number(item.versionId),
    originalName: item.originalName || null,
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
// history приходит от backend в порядке «новые сначала» (ORDER BY ... DESC —
// удобно для будущей постраничной подгрузки), а comments backend уже
// разворачивает в ASC перед возвратом (queryCommentsPage: array_reverse
// после ORDER BY id DESC, чтобы новая порция старых записей при подгрузке
// вставала в начало массива и сохраняла хронологический порядок). Поэтому
// разворачиваем перед merge-сортировкой только history — реверс уже ASC
// comments заново сломал бы их порядок. Array.prototype.sort стабилен, но
// occurredAt/createdAt приходят с backend с реальной микросекундной
// точностью (App\Infrastructure\Clock, issue #86), а new Date() в JS хранит
// только миллисекунды — два события, случившиеся в пределах одной
// миллисекунды (например, запись в request_transitions и в audit_events в
// одной транзакции), всё ещё могут получить одинаковый sortAt. Без
// предварительного разворота history стабильная сортировка сохранила бы
// его неверный DESC-порядок при таком совпадении.
export function buildFeed(history, comments) {
  const orderedHistory = [...history].reverse()
  return [...orderedHistory, ...comments].sort((a, b) => new Date(a.sortAt) - new Date(b.sortAt))
}

export function newestFirstFeed(history, comments) {
  return buildFeed(history, comments).reverse()
}

export function canSubmitComment(item, detailLoading) {
  return Boolean(item?.canComment) && !detailLoading
}

// У руководителя ИЦ/лаборатории canStart истинно уже при отсутствии
// исполнителя (право по ТЗ, WF-004) — кнопку показываем только после
// назначения, иначе заявка уходит «В работе» с executor = null (issue #135).
export function canStartNow(item) {
  return Boolean(item?.canStart && item?.executorId)
}

// SEC-002/SEC-003: approve/decline — решения последнего контроля СБ.
// null — контроль ещё не проводился. Символьное соответствие (было ✓/✕/—)
// сохранено семантически, изменилось только визуальное представление
// (issue #148) — иконка вместо текстового Unicode-символа, зависевшего от
// шрифта/ОС и не имевшего явной семантики для скринридеров.
// Классы с префиксом security-mark--, а не голые approve/decline/pending:
// styles.css общий на всё приложение, обычные слова легко столкнутся с
// каким-нибудь будущим (или уже существующим где-то) классом (Qodo).
const SECURITY_MARK_ICONS = {
  approve: { className: 'security-mark--approve', label: 'Согласовано', path: 'M3 8.5L6.5 12L13 4' },
  decline: { className: 'security-mark--decline', label: 'Не согласовано', path: 'M4 4L12 12M12 4L4 12' },
}
const DEFAULT_SECURITY_MARK_ICON = { className: 'security-mark--pending', label: 'Контроль ещё не проводился', path: 'M4 8H12' }

export function securityMarkIcon(securityMark) {
  return SECURITY_MARK_ICONS[securityMark] || DEFAULT_SECURITY_MARK_ICON
}

const DOCUMENT_KINDS = {
  'application/pdf': { label: 'PDF', className: 'pdf' },
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': { label: 'DOC', className: 'docx' },
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': { label: 'XLS', className: 'xlsx' },
  'image/png': { label: 'PNG', className: 'image' },
  'image/jpeg': { label: 'JPG', className: 'image' },
}
const DEFAULT_DOCUMENT_KIND = { label: '?', className: 'unknown' }

export function documentKind(mimeType) {
  return DOCUMENT_KINDS[mimeType] || DEFAULT_DOCUMENT_KIND
}

export function documentFromApi(item) {
  return {
    id: Number(item.id),
    title: item.title,
    documentType: item.documentType || 'attachment',
    commentId: item.commentId ? Number(item.commentId) : null,
    versionId: Number(item.versionId),
    version: Number(item.version),
    originalName: item.originalName,
    mimeType: item.mimeType,
    size: `${Math.max(1, Math.ceil(Number(item.sizeBytes) / 1024))} КБ`,
    sha256: item.sha256,
    uploadedBy: item.uploadedBy,
    createdAt: item.createdAt ? new Date(item.createdAt).toLocaleString('ru-RU', { timeZone: 'Europe/Moscow' }) : null,
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
