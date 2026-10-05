import { expect, test } from '@playwright/test'

test('форма показывает объём испытаний и карточки файлов; удалённый файл не загружается', async ({ page }) => {
  const identityHeader = process.env.E2E_IDENTITY_HEADER || 'X-Test-User-ID'
  await page.addInitScript(() => localStorage.setItem('ic.dev.userId', '6'))
  await page.route('**/api/**', route => route.continue({ headers: { ...route.request().headers(), [identityHeader]: '6' } }))
  await page.goto('/')
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).zoom)).toBe('1.1')
  expect(await page.locator('.registry-number-heading span').evaluate(element => getComputedStyle(element).textTransform)).toBe('uppercase')
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  const dialog = page.getByRole('dialog', { name: 'Заявка на проведение испытаний' })
  const scope = dialog.getByPlaceholder('Обозначьте объём и метод испытаний: укажите пункты документов, содержащих требования к образцу, а также метод или методику испытаний.')
  await expect(scope).toHaveAttribute('placeholder', 'Обозначьте объём и метод испытаний: укажите пункты документов, содержащих требования к образцу, а также метод или методику испытаний.')
  const input = dialog.getByLabel('Выбрать сопроводительные документы', { exact: true })
  await input.setInputFiles({ name: 'Сохранить.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') })
  await input.setInputFiles({ name: 'Ошибочный.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: Buffer.from('will be removed') })
  await expect(dialog.locator('.shlz-file-row')).toHaveCount(2)
  await expect(dialog.locator('.shlz-file-row__meta').first()).toContainText('PDF')
  const dataTransfer = await page.evaluateHandle(() => {
    const data = new DataTransfer()
    data.items.add(new File(['preview'], 'Чертёж.png', { type: 'image/png' }))
    return data
  })
  await dialog.locator('.shlz-file-upload__surface').dispatchEvent('drop', { dataTransfer })
  await dataTransfer.dispose()
  await expect(dialog.locator('.shlz-file-row')).toHaveCount(3)
  await dialog.getByRole('button', { name: 'Удалить Ошибочный.docx', exact: true }).click()
  await expect(dialog.getByRole('button', { name: 'Удалить Чертёж.png', exact: true })).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(dialog.locator('.shlz-file-row')).toHaveCount(1)
  await expect(dialog.getByRole('button', { name: 'Удалить Сохранить.pdf', exact: true })).toBeFocused()
  await page.setViewportSize({ width: 390, height: 844 })
  await dialog.locator('.shlz-file-row').scrollIntoViewIfNeeded()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  const modalBox = await dialog.boundingBox()
  expect(modalBox.y).toBeGreaterThanOrEqual(0)
  expect(modalBox.y + modalBox.height).toBeLessThanOrEqual(844)
  for (const button of await dialog.locator('.modal-actions button').all()) {
    const box = await button.boundingBox()
    expect(box.x).toBeGreaterThanOrEqual(modalBox.x)
    expect(box.x + box.width).toBeLessThanOrEqual(modalBox.x + modalBox.width)
  }
  await page.setViewportSize({ width: 1440, height: 1000 })
  await dialog.getByPlaceholder('Укажите наименование и тип продукции').fill(`Attachments-${Date.now()}`)
  await dialog.getByPlaceholder('Наименование производителя').fill('Завод')
  await dialog.getByPlaceholder('Наименование поставщика').fill('Поставщик')
  await scope.fill('Пункты 1–3 программы испытаний')
  const filenames = []
  page.on('request', request => {
    if (request.method() === 'POST' && /\/requests\/\d+\/documents$/.test(new URL(request.url()).pathname)) {
      filenames.push(request.postDataBuffer()?.toString().match(/filename="([^"]+)"/)?.[1])
    }
  })
  await dialog.getByRole('button', { name: 'Создать заявку', exact: true }).click()
  await expect(page.getByRole('heading', { name: /^Заявка №/ })).toBeVisible()
  await expect(page.getByText('Сохранить.pdf', { exact: true }).first()).toBeVisible()
  expect(filenames).toEqual(['Сохранить.pdf'])
})
