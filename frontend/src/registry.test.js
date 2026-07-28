import { describe, expect, it } from 'vitest'
import { filterRequests, fromApi, historyFromApi } from './registry'

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
  can_assign_executor: 1,
  can_start: 1,
}

it('maps the API contract to a registry row', () => {
  expect(fromApi(registered)).toMatchObject({
    id: '000007',
    status: 'Заявка зарегистрирована',
    product: 'Лебёдка',
    manufacturer: 'Завод',
    sampleQuantity: 2,
    executor: 'Сергей Кашин',
    lockVersion: 3,
    canAssignExecutor: true,
    canStart: true,
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
