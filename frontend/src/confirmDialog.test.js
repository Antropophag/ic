import { describe, expect, it } from 'vitest'
import { createConfirmDialog } from './confirmDialog'

describe('confirm dialog', () => {
  it('resolves true and closes when accepted', async () => {
    const dialog = createConfirmDialog()
    const result = dialog.ask('Отозвать эту заявку?', { confirmLabel: 'Отозвать' })

    expect(dialog.state.open).toBe(true)
    expect(dialog.state.message).toBe('Отозвать эту заявку?')
    expect(dialog.state.confirmLabel).toBe('Отозвать')
    expect(dialog.state.danger).toBe(false)

    dialog.accept()

    await expect(result).resolves.toBe(true)
    expect(dialog.state.open).toBe(false)
  })

  it('resolves false and closes when cancelled', async () => {
    const dialog = createConfirmDialog()
    const result = dialog.ask('Удалить отчёт?', { danger: true })

    expect(dialog.state.danger).toBe(true)

    dialog.cancel()

    await expect(result).resolves.toBe(false)
    expect(dialog.state.open).toBe(false)
  })

  it('cancels a pending prompt instead of leaving it unresolved when asked again', async () => {
    const dialog = createConfirmDialog()
    const first = dialog.ask('Первое действие?')
    const second = dialog.ask('Второе действие?')

    await expect(first).resolves.toBe(false)

    dialog.accept()

    await expect(second).resolves.toBe(true)
    expect(dialog.state.message).toBe('Второе действие?')
  })
})
