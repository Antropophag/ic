import { describe, expect, it, vi } from 'vitest'
import { confirmRequestAction } from './confirmRequestAction'

describe('confirmRequestAction', () => {
  it('cancels the action when another request is selected during confirmation', async () => {
    let selected = { backendId: 17, lockVersion: 3 }
    let resolveConfirmation
    const ask = () => new Promise((resolve) => { resolveConfirmation = resolve })
    const execute = vi.fn()

    const pending = confirmRequestAction(() => selected, ask)
      .then((context) => context && execute(context))
    selected = { backendId: 29, lockVersion: 1 }
    resolveConfirmation({ reason: 'Причина действия' })

    await pending

    expect(execute).not.toHaveBeenCalled()
  })

  it('returns the request context captured before confirmation', async () => {
    const selected = { backendId: 17, lockVersion: 3 }

    await expect(confirmRequestAction(
      () => selected,
      () => Promise.resolve({ reason: 'Причина действия' }),
    )).resolves.toEqual({
      requestId: 17,
      lockVersion: 3,
      confirmed: { reason: 'Причина действия' },
    })
  })
})
