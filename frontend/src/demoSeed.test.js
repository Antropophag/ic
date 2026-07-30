import { expect, it, vi } from 'vitest'
import { clearDemoRegistry, runDemoSeed } from './demoSeed'

it('reports a seed failure without refreshing the registry', async () => {
  const onSeeded = vi.fn()
  const refresh = vi.fn()

  await expect(runDemoSeed(
    vi.fn().mockRejectedValue(new Error('seed failed')),
    onSeeded,
    refresh,
  )).resolves.toBe('Не удалось заполнить демо-данные.')
  expect(onSeeded).not.toHaveBeenCalled()
  expect(refresh).not.toHaveBeenCalled()
})

it('preserves seed success when the registry refresh fails', async () => {
  const onSeeded = vi.fn()

  await expect(runDemoSeed(
    vi.fn().mockResolvedValue({ requests: 7 }),
    onSeeded,
    vi.fn().mockRejectedValue(new Error('refresh failed')),
  )).resolves.toBe('Создано демо-заявок: 7. Не удалось обновить список — обновите страницу.')
  expect(onSeeded).toHaveBeenCalledOnce()
})

it('reports seed success after refreshing the registry', async () => {
  await expect(runDemoSeed(
    vi.fn().mockResolvedValue({ requests: 7 }),
    vi.fn(),
    vi.fn().mockResolvedValue(undefined),
  )).resolves.toBe('Создано демо-заявок: 7.')
})

it('invalidates stale registry rows after a successful destructive seed', () => {
  const requests = { value: [{ backendId: 42 }] }
  const registryPage = {
    total: 1,
    page: 3,
    pageSize: 20,
    pageCount: 4,
    counts: { active: 1, all: 1, mine: 1 },
  }

  clearDemoRegistry(requests, registryPage)

  expect(requests.value).toEqual([])
  expect(registryPage).toEqual({
    total: 0,
    page: 1,
    pageSize: 20,
    pageCount: 1,
    counts: { active: 0, all: 0, mine: 0 },
  })
})

it('does not update disposed UI after a late seed response', async () => {
  const onSeeded = vi.fn()

  await expect(runDemoSeed(
    vi.fn().mockResolvedValue({ requests: 7 }),
    onSeeded,
    vi.fn(),
    () => false,
  )).resolves.toBeNull()
  expect(onSeeded).not.toHaveBeenCalled()
})
