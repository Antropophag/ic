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

  it('resolves the entered reason when a reason field is requested', async () => {
    const dialog = createConfirmDialog()
    const result = dialog.ask('Отказать в проведении испытаний по этой заявке?', {
      confirmLabel: 'Отказать',
      danger: true,
      reasonField: { required: false, placeholder: 'Причина' },
    })

    expect(dialog.state.reasonField).toEqual({ required: false, placeholder: 'Причина' })
    dialog.state.reasonValue = '  Образец повреждён  '
    dialog.accept()

    await expect(result).resolves.toEqual({ reason: 'Образец повреждён' })
  })

  it('resolves a truthy result for an empty optional reason, not a falsy one', async () => {
    const dialog = createConfirmDialog()
    const result = dialog.ask('Отозвать эту заявку?', { reasonField: { required: false } })

    dialog.accept()

    const settled = await result
    expect(settled).not.toBe(false)
    expect(settled).toEqual({ reason: '' })
  })

  it('resets reasonField and reasonValue for a plain prompt asked afterwards', async () => {
    const dialog = createConfirmDialog()
    dialog.ask('Вернуть в работу?', { reasonField: { required: true } })
    dialog.state.reasonValue = 'Не соответствует'
    dialog.accept()

    const plain = dialog.ask('Начать работу?')
    expect(dialog.state.reasonField).toBeNull()
    expect(dialog.state.reasonValue).toBe('')

    dialog.accept()
    await expect(plain).resolves.toBe(true)
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
