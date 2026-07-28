import { describe, expect, it } from 'vitest'
import { canSubmitComment, commentFromApi, documentFromApi, filterRequests, fromApi, historyFromApi } from './registry'

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
  can_assign_expert: 0,
  can_start: 1,
  can_comment: 1,
  can_upload_document: 1,
  can_upload_report: 0,
}

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
    canAssignExpert: false,
    canStart: true,
    canComment: true,
    canUploadDocument: true,
    canUploadReport: false,
  })
})

it('preserves an unknown status so API drift stays visible', () => {
  expect(fromApi({ ...registered, status: 'new_status' }).status).toBe('new_status')
})

it('hides the start action after the request leaves registered status', () => {
  expect(fromApi({ ...registered, status: 'in_progress', can_start: 1 })).toMatchObject({
    status: 'Заявка в работе',
    tone: 'cyan',
    canStart: false,
  })
})

it('maps the report stage, permission and history label', () => {
  expect(fromApi({ ...registered, status: 'opinion_preparation', can_upload_report: 1, can_assign_expert: 1 })).toMatchObject({
    status: 'Подготовка заключения',
    tone: 'violet',
    canUploadReport: true,
    canAssignExpert: true,
  })
  expect(historyFromApi({
    id: 10, kind: 'transition', action: 'upload_report', actorName: 'Исполнитель',
    ruleId: 'DOC-002', occurredAt: '2026-07-28T10:00:00Z',
  }).description).toBe('загрузил(а) отчёт испытаний')
  expect(historyFromApi({
    id: 11, kind: 'assignment', action: 'assign_expert', actorName: 'Руководитель',
    ruleId: 'WF-010', occurredAt: '2026-07-28T10:01:00Z',
  }).description).toBe('назначил(а) эксперта')
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
    description: 'перевёл(а) заявку в работу',
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

it('maps the latest document version without exposing its storage key', () => {
  expect(documentFromApi({
    id: '4', title: 'report.pdf', versionId: '12', version: '2', originalName: 'report.pdf',
    mimeType: 'application/pdf', sizeBytes: 1500, sha256: 'a'.repeat(64),
    uploadedBy: 'Иван Иванов', createdAt: '2026-07-28T10:00:00Z',
  })).toMatchObject({ id: 4, documentType: 'attachment', versionId: 12, version: 2, size: '2 КБ' })
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
