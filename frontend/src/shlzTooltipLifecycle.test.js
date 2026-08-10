import { describe, expect, it, vi } from 'vitest'
import { createShlzTooltipLifecycle } from './shlzTooltipLifecycle'

describe('SHLZ tooltip lifecycle', () => {
  it('destroys current controllers before enhancing rendered content again', async () => {
    const first = { destroy: vi.fn() }
    const second = { destroy: vi.fn() }
    const enhance = vi.fn().mockResolvedValueOnce([first]).mockResolvedValueOnce([second])
    const lifecycle = createShlzTooltipLifecycle({
      enabled: () => true,
      root: () => ({}),
      enhance: () => enhance,
      afterRender: async () => {},
    })

    await lifecycle.refresh()
    await lifecycle.refresh()
    expect(first.destroy).toHaveBeenCalledOnce()
    lifecycle.destroy()
    expect(second.destroy).toHaveBeenCalledOnce()
  })

  it('destroys controllers returned by an obsolete async refresh', async () => {
    let resolveFirst
    const obsolete = { destroy: vi.fn() }
    const current = { destroy: vi.fn() }
    const enhance = vi.fn()
      .mockReturnValueOnce(new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValueOnce([current])
    const lifecycle = createShlzTooltipLifecycle({
      enabled: () => true,
      root: () => ({}),
      enhance: () => enhance,
      afterRender: async () => {},
    })

    const firstRefresh = lifecycle.refresh()
    await lifecycle.refresh()
    resolveFirst([obsolete])
    await firstRefresh
    expect(obsolete.destroy).toHaveBeenCalledOnce()
    expect(current.destroy).not.toHaveBeenCalled()
  })
})
