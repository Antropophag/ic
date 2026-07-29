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

  it('only settles the most recent prompt when a new one is asked before resolving', async () => {
    const dialog = createConfirmDialog()
    const first = dialog.ask('Первое действие?')
    const second = dialog.ask('Второе действие?')

    dialog.accept()

    await expect(second).resolves.toBe(true)
    expect(dialog.state.message).toBe('Второе действие?')
    // Первый промис так и не был разрешён — вызывающая функция для него уже
    // не важна, но и не должна повиснуть навсегда: проверяем, что accept()
    // не пытается разрешить его повторно другим значением.
    let firstSettled = false
    first.then(() => { firstSettled = true })
    await Promise.resolve()
    expect(firstSettled).toBe(false)
  })
})
