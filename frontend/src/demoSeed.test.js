import { expect, it, vi } from 'vitest'
import { runDemoSeed } from './demoSeed'

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
