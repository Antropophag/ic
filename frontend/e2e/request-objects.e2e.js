import { expect, request as playwrightRequest, test } from '@playwright/test'

const identityHeader = process.env.E2E_IDENTITY_HEADER || 'X-Test-User-ID'

test('создание трёх объектов, восстановление черновика, повторное открытие и поиск второй позиции', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  let actor = '3'
  await page.addInitScript(() => localStorage.setItem('ic.dev.userId', '3'))
  await page.route('**/api/**', route => route.continue({ headers: { ...route.request().headers(), [identityHeader]: actor } }))
  const marker = `Objects-${Date.now()}`
  await page.goto('/')
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  for (let index = 0; index < 3; index += 1) {
    if (index) await page.getByRole('button', { name: 'Добавить объект', exact: true }).click()
    await page.getByPlaceholder('Укажите наименование и тип продукции').nth(index).fill(`${marker}-${index + 1}`)
    await page.getByRole('textbox', { name: `Количество образцов ${index + 1}`, exact: false }).fill(index === 0 ? '4 шт по 3 метра' : '2 комплекта')
  }
  await page.getByPlaceholder('Наименование производителя').fill('Общий производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Общий поставщик')
  await page.getByPlaceholder('Обозначьте объём и метод испытаний: укажите пункты документов, содержащих требования к образцу, а также метод или методику испытаний.').fill('Общие требования и методы')
  await page.reload()
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  await expect(page.getByPlaceholder('Укажите наименование и тип продукции')).toHaveCount(3)
  await expect(page.getByPlaceholder('Укажите наименование и тип продукции').nth(2)).toHaveValue(`${marker}-3`)
  const saved = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/v1/requests')
  await page.getByRole('button', { name: 'Создать заявку', exact: true }).click()
  expect((await saved).status()).toBe(201)
  await expect(page.getByRole('heading', { name: 'Объекты испытаний · 3' })).toBeVisible()
  const requestUrl = page.url()
  await expect(page.getByRole('button', { name: 'Показать объекты' })).toHaveAttribute('aria-expanded', 'false')
  await page.getByRole('button', { name: 'Показать объекты' }).click()
  await expect(page.getByRole('cell', { name: '4 шт по 3 метра', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Копировать наименование объекта 2' }).click()
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(`${marker}-2`)
  await page.reload()
  await expect(page.getByRole('button', { name: 'Показать объекты' })).toBeVisible()
  actor = '1'
  await page.goto(requestUrl)
  await expect(page.getByRole('button', { name: 'Свернуть список' })).toHaveAttribute('aria-expanded', 'true')
  await expect(page.getByRole('rowheader', { name: `${marker}-3`, exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Вернуться к списку заявок', exact: true }).click()
  await page.getByPlaceholder('Поиск по заявкам').fill(`${marker}-2`)
  await expect(page.locator('.registry tbody tr')).toHaveCount(1)
  await expect(page.locator('.registry tbody')).toContainText('Объектов: 3')
  await expect(page.locator('.registry-object-tooltip')).toHaveAttribute('data-tooltip', `${marker}-1\n${marker}-2\n${marker}-3`)
  const tooltip = page.locator('.registry-object-tooltip')
  await tooltip.hover()
  await expect.poll(() => tooltip.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1')
  const content = await tooltip.evaluate(element => getComputedStyle(element, '::after').content)
  expect(content).toContain(`${marker}-1`)
  expect(content).toContain(`${marker}-2`)
  expect(content).toContain(`${marker}-3`)
  expect(await tooltip.evaluate(element => getComputedStyle(element, '::after').whiteSpace)).toBe('pre-line')
  await page.locator('.registry tbody tr').click()
  await expect(page.getByRole('heading', { name: 'Объекты испытаний · 3' })).toBeVisible()
})

test('API проверяет границы списка и текстового количества, сохраняя старый формат', async ({ baseURL }) => {
  const api = await playwrightRequest.newContext({ baseURL, extraHTTPHeaders: { [identityHeader]: '3' } })
  try {
    const me = await api.get('/api/v1/auth/me')
    expect(me.ok()).toBe(true)
    const { csrfToken } = await me.json()
    const create = data => api.post('/api/v1/requests', { data, headers: { 'X-CSRF-Token': csrfToken, 'Idempotency-Key': crypto.randomUUID() } })
    const common = { manufacturer: 'Завод', supplier: 'Поставщик', testMethod: 'Программа' }
    const object = { productName: 'Тестовая позиция', sampleQuantity: '4 шт по 3 метра' }
    for (const objects of [[], Array(11).fill(object), [{ ...object, sampleQuantity: 'я'.repeat(16) }], [{ ...object, productName: ' ' }]]) {
      expect((await create({ ...common, objects })).status()).toBe(422)
    }
    for (const count of [1, 10]) {
      const response = await create({ ...common, objects: Array(count).fill(object) })
      expect(response.status(), await response.text()).toBe(201)
      const { id } = await response.json()
      const detail = await (await api.get(`/api/v1/requests/${id}`)).json()
      expect(detail.item.objects).toHaveLength(count)
      expect(detail.item.objects[0]).toEqual(object)
    }
    expect((await create({ ...common, productName: 'Старый клиент', sampleQuantity: 2 })).status()).toBe(201)
  } finally {
    await api.dispose()
  }
})

test('кнопки объектов заметны, удаление компактно, фокус остаётся в форме', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('ic.dev.userId', '3'))
  await page.route('**/api/**', route => route.continue({ headers: { ...route.request().headers(), [identityHeader]: '3' } }))
  await page.goto('/')
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  const add = page.getByRole('button', { name: 'Добавить объект', exact: true })
  await expect(add).toHaveClass(/shlz-button--primary/)
  await add.click()
  const names = page.getByPlaceholder('Укажите наименование и тип продукции')
  await expect(names.nth(1)).toBeFocused()
  await names.nth(0).fill('Сохранённый объект')
  const remove = page.getByRole('button', { name: 'Удалить объект 2', exact: true })
  await expect(remove).toHaveClass(/shlz-button--icon/)
  const box = await remove.boundingBox()
  expect(Math.abs(box.width - box.height)).toBeLessThan(1)
  await page.setViewportSize({ width: 390, height: 844 })
  await remove.scrollIntoViewIfNeeded()
  const quantity = await page.getByRole('textbox', { name: 'Количество образцов 2' }).boundingBox()
  const mobileRemove = await remove.boundingBox()
  expect(mobileRemove.x).toBeGreaterThan(quantity.x + quantity.width)
  expect(Math.abs(mobileRemove.y + mobileRemove.height - quantity.y - quantity.height)).toBeLessThan(2)
  await remove.click()
  await expect(names).toHaveCount(1)
  await expect(names.first()).toHaveValue('Сохранённый объект')
  await expect(names.first()).toBeFocused()
  await expect(page.getByRole('button', { name: 'Удалить объект 1', exact: true })).toHaveCount(0)
})
