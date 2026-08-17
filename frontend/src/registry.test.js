import { describe, expect, it } from 'vitest'
import { avatarRoleClass, buildFeed, canStartNow, canSubmitComment, commentFromApi, documentFromApi, documentKind, filterRequests, fromApi, historyFromApi, initialsFor, newestFirstFeed, paginate, sampleQuantityDisplay, securityMarkIcon, withoutStaleActions } from './registry'

const registered = {
  id: 4,
  number: 7,
  status: 'registered',
  product_name: 'Лебёдка',
  manufacturer: 'Завод',
  supplier: 'Поставщик',
  sample_quantity: 2,
  test_method: 'Ресурсный метод',
  created_at: '2026-07-28T08:00:00Z',
  initiator_name: 'Максим Умнов',
  department: 'Бюро',
  lockVersion: 3,
  executor_id: 2,
  executor_name: 'Сергей Кашин',
  expert_id: 4,
  expert_name: 'Анна Смирнова',
  can_assign_executor: 1,
  can_claim_expert: 0,
  can_reassign_expert: 0,
  can_start: 1,
  can_comment: 1,
  can_upload_document: 1,
  can_upload_report: 0,
  can_publish_opinion: 0,
  can_security_decide: 0,
}

it('maps portal roles to stable avatar classes and safely falls back to employee', () => {
  expect(avatarRoleClass('ic_executor')).toBe('avatar-role--ic-executor')
  expect(avatarRoleClass('expert')).toBe('avatar-role--expert')
  expect(avatarRoleClass('unknown-role')).toBe('avatar-role--employee')
})

it('maps the API contract to a registry row', () => {
  expect(fromApi(registered)).toMatchObject({
    id: '000007',
    status: 'Заявка зарегистрирована',
    product: 'Лебёдка',
    manufacturer: 'Завод',
    sampleQuantity: 2,
    executor: 'Сергей Кашин',
    expert: 'Анна Смирнова',
    lockVersion: 3,
    canAssignExecutor: true,
    canClaimExpert: false,
    canReassignExpert: false,
    canStart: true,
    canComment: true,
    canUploadDocument: true,
    canUploadReport: false,
    canPublishOpinion: false,
    canSecurityDecide: false,
    canEditDepartment: false,
  })
})

it('shows the original Bitrix24 quantity when an archived exact quantity is unknown', () => {
  const request = fromApi({
    ...registered,
    is_archived: 1,
    sample_quantity: null,
    legacy_sample_quantity_raw: 'По 1 шт. каждого вида',
  })

  expect(request.sampleQuantity).toBeNull()
  expect(request.legacySampleQuantityRaw).toBe('По 1 шт. каждого вида')
  expect(sampleQuantityDisplay(request)).toBe('По 1 шт. каждого вида')
})

it('keeps the normalized quantity display for regular and unambiguous archived requests', () => {
  expect(sampleQuantityDisplay(fromApi(registered))).toBe('2 шт.')
  expect(sampleQuantityDisplay(fromApi({
    ...registered,
    is_archived: 1,
    sample_quantity: 5,
    legacy_sample_quantity_raw: '5шт',
  }))).toBe('5 шт.')
})

it('maps administrative department permission and its feed event', () => {
  expect(fromApi({ ...registered, can_edit_department: 1 }).canEditDepartment).toBe(true)
  expect(historyFromApi({
    id: 8,
    kind: 'audit',
    action: 'change_department',
    actorName: 'Администратор',
    targetName: 'Подразделение C',
    ruleId: 'REQ-011',
    occurredAt: '2026-08-05T10:00:00Z',
  })).toMatchObject({
    actor: 'Администратор',
    description: 'Подразделение заявки изменено: Подразделение C',
    ruleId: 'REQ-011',
  })
})

it('preserves an unknown status so API drift stays visible', () => {
  expect(fromApi({ ...registered, status: 'new_status' })).toMatchObject({
    status: 'new_status',
    compactStatus: 'new_status',
    tone: 'request-status--unknown',
  })
})

it.each([
  ['registered', 'request-status--registered'],
  ['in_progress', 'request-status--in-progress'],
  ['suspended', 'request-status--suspended'],
  ['opinion_preparation', 'request-status--expertise'],
  ['security_review', 'request-status--security'],
  ['completed', 'request-status--completed'],
  ['rejected', 'request-status--rejected'],
  ['withdrawn', 'request-status--withdrawn'],
])('maps %s to a status-specific semantic tone', (status, tone) => {
  expect(fromApi({ ...registered, status }).tone).toBe(tone)
})

it('uses a compact rejected label in the registry without changing the business label', () => {
  expect(fromApi({ ...registered, status: 'rejected' })).toMatchObject({
    status: 'В проведении испытаний отказано',
    compactStatus: 'Отказано',
  })
})

it('maps the manual color mark for a manager', () => {
  expect(fromApi({ ...registered, color: 'red', can_set_color: 1 })).toMatchObject({
    color: 'red',
    canSetColor: true,
  })
})

it('falls back to white for a missing or unknown color', () => {
  expect(fromApi(registered).color).toBe('white')
  expect(fromApi({ ...registered, color: 'not-a-color' }).color).toBe('white')
})

it('hides the start action after the request leaves registered status', () => {
  expect(fromApi({ ...registered, status: 'in_progress', can_start: 1 })).toMatchObject({
    status: 'Заявка в работе',
    tone: 'request-status--in-progress',
    canStart: false,
  })
})

it('disables every version-sensitive action before conflict recovery', () => {
  expect(withoutStaleActions({
    canAssignExecutor: true,
    canClaimExpert: true,
    canReassignExpert: true,
    canPublishOpinion: true,
    canSecurityDecide: true,
    canStart: true,
    canSetColor: true,
    canReject: true,
    canWithdraw: true,
    canDeleteReport: true,
    canEditDepartment: true,
  })).toMatchObject({
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
    canEditDepartment: false,
  })
})

it('maps reject and withdraw permissions and history labels', () => {
  expect(fromApi({ ...registered, can_reject: 1, can_withdraw: 1 })).toMatchObject({
    canReject: true,
    canWithdraw: true,
  })
  expect(historyFromApi({
    id: 14, kind: 'transition', action: 'reject', actorName: 'Руководитель',
    ruleId: 'WF-006', occurredAt: '2026-07-28T10:04:00Z',
  }).description).toBe('В проведении испытаний отказано')
  expect(historyFromApi({
    id: 15, kind: 'transition', action: 'withdraw', actorName: 'Тестовый сотрудник',
    ruleId: 'WF-007', occurredAt: '2026-07-28T10:05:00Z',
  }).description).toBe('Заявка отозвана')
})

it('maps suspend and resume history actions to user-facing labels', () => {
  expect(historyFromApi({
    id: 22, kind: 'transition', action: 'suspend', actorName: 'Исполнитель',
    ruleId: 'WF-010', occurredAt: '2026-07-31T10:00:00Z',
  }).description).toBe('Работа по заявке приостановлена')
  expect(historyFromApi({
    id: 23, kind: 'transition', action: 'resume', actorName: 'Исполнитель',
    ruleId: 'WF-011', occurredAt: '2026-07-31T11:00:00Z',
  }).description).toBe('Работа по заявке возобновлена')
})

it('maps the report stage, permission and history label', () => {
  expect(fromApi({ ...registered, status: 'opinion_preparation', can_upload_report: 1, can_claim_expert: 1 })).toMatchObject({
    status: 'Подготовка заключения',
    tone: 'request-status--expertise',
    canUploadReport: true,
    canClaimExpert: true,
  })
  expect(historyFromApi({
    id: 10, kind: 'transition', action: 'upload_report', actorName: 'Исполнитель',
    ruleId: 'DOC-002', occurredAt: '2026-07-28T10:00:00Z',
  }).description).toBe('Отчёт испытаний загружен')
  expect(historyFromApi({
    id: 10, kind: 'transition', action: 'upload_report', actorName: 'Исполнитель',
    ruleId: 'DOC-002', occurredAt: '2026-07-28T10:00:00Z',
  }).action).toBe('upload_report')
  expect(historyFromApi({
    id: 11, kind: 'assignment', action: 'claim_expert', actorName: 'Эксперт',
    ruleId: 'WF-010', occurredAt: '2026-07-28T10:01:00Z',
  }).description).toBe('Эксперт взял заявку в работу')
})

it('maps the reassign-expert history label', () => {
  expect(historyFromApi({
    id: 16, kind: 'assignment', action: 'reassign_expert', actorName: 'Эксперт',
    ruleId: 'WF-011', occurredAt: '2026-07-28T10:06:00Z',
  }).description).toBe('Эксперт переназначен')
})

it('maps a downloadable report reference only when backend grants access', () => {
  expect(historyFromApi({
    id: 20, kind: 'transition', action: 'upload_report', actorName: 'Исполнитель',
    ruleId: 'DOC-002', occurredAt: '2026-07-28T10:00:00Z', versionId: '42', originalName: 'Отчёт.pdf',
  })).toMatchObject({ versionId: 42, originalName: 'Отчёт.pdf' })

  expect(historyFromApi({
    id: 21, kind: 'transition', action: 'upload_report', actorName: 'Исполнитель',
    ruleId: 'DOC-002', occurredAt: '2026-07-28T10:00:00Z', versionId: null, originalName: null,
  })).toMatchObject({ versionId: null, originalName: null })
})

it('appends the target name for assign_executor and reassign_expert', () => {
  expect(historyFromApi({
    id: 17, kind: 'assignment', action: 'assign_executor', actorName: 'Руководитель',
    targetName: 'Сергей Кашин', ruleId: 'WF-001', occurredAt: '2026-07-28T10:07:00Z',
  }).description).toBe('Исполнитель назначен: Сергей Кашин')
  expect(historyFromApi({
    id: 18, kind: 'assignment', action: 'reassign_expert', actorName: 'Эксперт',
    targetName: 'Виктор Дорохов', ruleId: 'WF-011', occurredAt: '2026-07-28T10:08:00Z',
  }).description).toBe('Эксперт переназначен: Виктор Дорохов')
})

it('ignores the target name for claim_expert (self-assignment)', () => {
  expect(historyFromApi({
    id: 19, kind: 'assignment', action: 'claim_expert', actorName: 'Эксперт',
    targetName: 'Эксперт', ruleId: 'WF-010', occurredAt: '2026-07-28T10:09:00Z',
  }).description).toBe('Эксперт взял заявку в работу')
})

it('maps the delete-report permission and history label', () => {
  expect(fromApi({ ...registered, status: 'completed', can_delete_report: 1 }))
    .toMatchObject({ canDeleteReport: true })
  expect(fromApi(registered)).toMatchObject({ canDeleteReport: false })
  expect(historyFromApi({
    id: 17, kind: 'assignment', action: 'delete_report', actorName: 'Исполнитель',
    ruleId: 'DOC-011', occurredAt: '2026-07-28T10:07:00Z',
  }).description).toBe('Отчёт испытаний удалён')
})

it('maps permission and history for publishing an expert opinion', () => {
  expect(fromApi({ ...registered, status: 'opinion_preparation', can_publish_opinion: 1 }))
    .toMatchObject({ canPublishOpinion: true })
  expect(historyFromApi({
    id: 12, kind: 'transition', action: 'publish_opinion', actorName: 'Эксперт',
    ruleId: 'DOC-007', occurredAt: '2026-07-28T10:02:00Z',
  }).description).toBe('Экспертное заключение опубликовано')
})

it('maps the security decision permission and history', () => {
  expect(fromApi({ ...registered, status: 'security_review', can_security_decide: 1 }))
    .toMatchObject({ canSecurityDecide: true, status: 'Контроль СБ' })
  expect(historyFromApi({
    id: 13, kind: 'transition', action: 'security_return', actorName: 'Сотрудник СБ',
    reason: 'Уточнить вывод', ruleId: 'SEC-003', occurredAt: '2026-07-28T10:03:00Z',
  }).description).toBe('Заявка возвращена в работу: Уточнить вывод')
})

it('maps a safe history event without audit payload', () => {
  expect(historyFromApi({
    id: 9,
    kind: 'transition',
    action: 'start',
    actorName: 'Сергей Кашин',
    ruleId: 'WF-004',
    occurredAt: '2026-07-28T10:00:00Z',
  })).toMatchObject({
    id: 'transition-9',
    actor: 'Сергей Кашин',
    description: 'Заявка переведена в работу',
    ruleId: 'WF-004',
  })
})

it('maps a server-authored comment', () => {
  expect(commentFromApi({
    id: '12', authorName: 'Иван Иванов', body: 'Готово', createdAt: '2026-07-28T10:00:00Z',
  })).toMatchObject({ id: 12, author: 'Иван Иванов', body: 'Готово' })
})

it('does not allow a comment while an older detail response can still arrive', () => {
  expect(canSubmitComment({ canComment: true }, true)).toBe(false)
  expect(canSubmitComment({ canComment: true }, false)).toBe(true)
  expect(canSubmitComment({ canComment: false }, false)).toBe(false)
})

it('hides "Начать работу" for a leader until an executor is assigned', () => {
  expect(canStartNow({ canStart: true, executorId: null })).toBe(false)
  expect(canStartNow({ canStart: true, executorId: 7 })).toBe(true)
  expect(canStartNow({ canStart: false, executorId: 7 })).toBe(false)
})

it('maps the latest document version without exposing its storage key', () => {
  expect(documentFromApi({
    id: '4', title: 'report.pdf', versionId: '12', version: '2', originalName: 'report.pdf',
    mimeType: 'application/pdf', sizeBytes: 1500, sha256: 'a'.repeat(64),
    uploadedBy: 'Иван Иванов', createdAt: '2026-07-28T10:00:00Z',
  })).toMatchObject({ id: 4, documentType: 'attachment', versionId: 12, version: 2, size: '2 КБ' })
  expect(documentFromApi({ id: '5', commentId: '42' }).commentId).toBe(42)
  expect(documentFromApi({ id: '6' }).commentId).toBeNull()
})

it('maps mime types to a document kind for the file icon', () => {
  expect(documentKind('application/pdf')).toEqual({ label: 'PDF', className: 'pdf' })
  expect(documentKind('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
    .toEqual({ label: 'DOC', className: 'docx' })
  expect(documentKind('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
    .toEqual({ label: 'XLS', className: 'xlsx' })
  expect(documentKind('image/png')).toEqual({ label: 'PNG', className: 'image' })
  expect(documentKind('image/jpeg')).toEqual({ label: 'JPG', className: 'image' })
  expect(documentKind('application/octet-stream')).toEqual({ label: '?', className: 'unknown' })
})

describe('registry filters', () => {
  const requests = [
    fromApi(registered),
    { ...fromApi(registered), id: '000008', initiator: 'Другой сотрудник', status: 'Заявка выполнена' },
  ]

  it('shows only active requests on the active tab', () => {
    expect(filterRequests(requests, { tab: 'active', query: '', status: '', currentUser: 'Максим Умнов' }))
      .toHaveLength(1)
  })

  it('filters own requests, status and case-insensitive text', () => {
    expect(filterRequests(requests, {
      tab: 'mine', query: 'ЛЕБЁДКА', status: 'Заявка зарегистрирована', currentUser: 'Максим Умнов',
    })).toEqual([requests[0]])
  })

  it('returns nothing when the query does not match', () => {
    expect(filterRequests(requests, { tab: 'all', query: 'нет такого', status: '', currentUser: '' }))
      .toEqual([])
  })
})

describe('registry sorting and pagination', () => {
  const byNumber = [1, 2, 3].map(backendId => ({ ...fromApi(registered), backendId, id: String(backendId).padStart(6, '0') }))

  it('sorts by request number, newest first by default', () => {
    expect(filterRequests(byNumber, { tab: 'all', query: '', status: '', currentUser: '' }).map(item => item.backendId))
      .toEqual([3, 2, 1])
  })

  it('sorts ascending when requested', () => {
    expect(filterRequests(byNumber, { tab: 'all', query: '', status: '', currentUser: '', sortDirection: 'asc' }).map(item => item.backendId))
      .toEqual([1, 2, 3])
  })

  it('paginates a page of items and clamps an out-of-range page', () => {
    const items = Array.from({ length: 25 }, (_, i) => i)

    expect(paginate(items, 1, 10)).toEqual({ items: items.slice(0, 10), page: 1, pageCount: 3, total: 25 })
    expect(paginate(items, 3, 10)).toEqual({ items: items.slice(20, 25), page: 3, pageCount: 3, total: 25 })
    expect(paginate(items, 99, 10).page).toBe(3)
  })

  it('never returns a page below 1', () => {
    expect(paginate([1, 2, 3], 0, 10).page).toBe(1)
  })
})

it('merges history and comments into one chronological feed', () => {
  const history = [
    historyFromApi({ id: 1, kind: 'transition', action: 'start', actorName: 'А', ruleId: 'WF-004', occurredAt: '2026-07-28T10:02:00Z' }),
  ]
  const comments = [
    commentFromApi({ id: 5, authorName: 'Б', body: 'раньше всех', createdAt: '2026-07-28T10:00:00Z' }),
    commentFromApi({ id: 6, authorName: 'В', body: 'позже всех', createdAt: '2026-07-28T10:05:00Z' }),
  ]

  const feed = buildFeed(history, comments)

  expect(feed.map(entry => entry.id)).toEqual([5, 'transition-1', 6])
  expect(feed.map(entry => entry.type)).toEqual(['comment', 'milestone', 'comment'])
})

it('presents the merged feed with the newest entry first', () => {
  const history = [
    historyFromApi({ id: 1, kind: 'transition', action: 'start', actorName: 'А', ruleId: 'WF-004', occurredAt: '2026-07-28T10:02:00Z' }),
  ]
  const comments = [
    commentFromApi({ id: 5, authorName: 'Б', body: 'раньше всех', createdAt: '2026-07-28T10:00:00Z' }),
    commentFromApi({ id: 6, authorName: 'В', body: 'позже всех', createdAt: '2026-07-28T10:05:00Z' }),
  ]

  expect(newestFirstFeed(history, comments).map(entry => entry.id)).toEqual([6, 'transition-1', 5])
})

it('keeps chronological order within a feed when entries share the same second', () => {
  // Backend отдаёт history/comments в порядке "новые сначала" (для
  // постраничной подгрузки); при равных occurredAt (секундная точность,
  // несколько действий подряд) стабильная сортировка обязана сохранить
  // правильный ASC-порядок внутри каждого источника, а не DESC-порядок API.
  const sameSecond = '2026-07-29T19:08:23.000000Z'
  const history = [
    historyFromApi({ id: 2, kind: 'transition', action: 'upload_report', actorName: 'Исполнитель', ruleId: 'DOC-002', occurredAt: sameSecond }),
    historyFromApi({ id: 1, kind: 'assignment', action: 'assign_executor', actorName: 'Руководитель', ruleId: 'WF-001', occurredAt: sameSecond }),
  ]

  const feed = buildFeed(history, [])

  expect(feed.map(entry => entry.id)).toEqual(['assignment-1', 'transition-2'])
})

it('does not re-reverse comments that the backend already returns in ascending order', () => {
  // queryCommentsPage() на backend уже делает array_reverse() после ORDER BY
  // c.id DESC — comments приходят в buildFeed уже в правильном ASC-порядке,
  // в отличие от history. Повторный reverse() внутри buildFeed сломал бы
  // порядок именно при одинаковом createdAt (секундная точность).
  const sameSecond = '2026-07-29T19:08:23.000000Z'
  const comments = [
    commentFromApi({ id: 10, authorName: 'А', body: 'первый по id, пришёл первым от backend', createdAt: sameSecond }),
    commentFromApi({ id: 11, authorName: 'Б', body: 'второй по id, пришёл вторым от backend', createdAt: sameSecond }),
  ]

  const feed = buildFeed([], comments)

  expect(feed.map(entry => entry.id)).toEqual([10, 11])
})

it('maps the last comment and report indicator for the registry row', () => {
  expect(fromApi({
    ...registered,
    last_comment_author: 'Сергей Кашин',
    last_comment_body: 'Образец передан на испытания',
    last_comment_created_at: '2026-07-29T10:00:00Z',
    has_report: 1,
    report_version_id: 55,
    report_original_name: 'report.pdf',
  })).toMatchObject({
    lastCommentAuthor: 'Сергей Кашин',
    lastCommentBody: 'Образец передан на испытания',
    hasReport: true,
    reportVersionId: 55,
    reportOriginalName: 'report.pdf',
  })
})

it('treats an absent last comment and report as null/false, not the DB default', () => {
  expect(fromApi(registered)).toMatchObject({
    lastCommentAuthor: null,
    lastCommentBody: null,
    lastCommentAt: null,
    hasReport: false,
    reportVersionId: null,
    reportOriginalName: null,
  })
})

it('builds initials for an arbitrary display name, not just the logged-in user', () => {
  expect(initialsFor('Максим Умнов')).toBe('МУ')
  expect(initialsFor('Сергей  Кашин')).toBe('СК')
  expect(initialsFor('Плюшкин')).toBe('П')
  expect(initialsFor('')).toBe('?')
  expect(initialsFor(null)).toBe('?')
})

it('maps the raw security_mark code, not a display glyph (issue #148)', () => {
  expect(fromApi({ ...registered, security_mark: 'approve' }).securityMark).toBe('approve')
  expect(fromApi({ ...registered, security_mark: 'return' }).securityMark).toBe('return')
  expect(fromApi({ ...registered, security_mark: null }).securityMark).toBeNull()
})

it('precomputes securityMarkDisplay once at mapping time, not per template read', () => {
  expect(fromApi({ ...registered, security_mark: 'approve' }).securityMarkDisplay).toMatchObject({
    className: 'security-mark--approve',
    label: 'Согласовано',
  })
})

it('resolves a security mark icon for each SEC-002/SEC-003 state', () => {
  expect(securityMarkIcon('approve')).toMatchObject({ className: 'security-mark--approve', label: 'Согласовано' })
  expect(securityMarkIcon('return')).toMatchObject({ className: 'security-mark--return', label: 'Возвращено на доработку' })
  expect(securityMarkIcon(null)).toMatchObject({ className: 'security-mark--pending', label: 'Контроль ещё не проводился' })
  expect(securityMarkIcon('unexpected')).toMatchObject({ className: 'security-mark--pending' })
})
