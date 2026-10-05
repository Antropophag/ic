import { expect, test } from '@playwright/test'

const sample = {
  id: 42, number: 42, created_at: '2026-10-04T10:00:00Z', status: 'registered',
  initiator_name: 'Тестов Александр Александрович', initiator_position: 'Ведущий инженер',
  department: 'Испытательная лаборатория', product_name: 'Комплект направляющих модели Т-42',
  manufacturer: 'Тестовый завод', supplier: 'Тестовый поставщик', sample_quantity: 2,
  test_method: 'Испытания по программе', color: 'white', lockVersion: 1,
}

async function openCard(page, overrides = {}) {
  const item = { ...sample, ...overrides }
  await page.addInitScript(() => localStorage.setItem('ic.dev.userId', '1'))
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    const user = { id: 1, displayName: 'Пользователь демо', position: 'Сотрудник', roles: item.can_edit_department ? ['employee', 'administrator'] : ['employee'] }
    let json = { items: [], categories: [], counts: { active: 0, all: 0, mine: 0 }, total: 0, page: 1, pageSize: 10, pageCount: 0 }
    if (path.endsWith('/auth/me')) json = { user, csrfToken: 'test-token' }
    if (path.endsWith('/dev/users')) json = { items: [user] }
    if (path.endsWith('/requests/42')) json = { item, history: [], comments: [], commentsPage: { hasMore: false }, documents: [] }
    if (path.endsWith('/requests/42/department')) {
      item.department = route.request().postDataJSON().department
      item.lockVersion += 1
      json = { item }
    }
    await route.fulfill({ json })
  })
  await page.goto('/?request=42')
  await expect(page.getByRole('heading', { name: 'Заявка №000042 от 04.10.2026' })).toBeVisible()
  return item
}

test('копируются только выбранные реквизиты; результат относится к последнему действию', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  await openCard(page)
  await expect(page.locator('.request-heading')).toContainText(sample.initiator_position)
  await expect(page.locator('.request-heading')).toContainText(sample.department)
  await page.getByRole('button', { name: 'Копировать заявку с датой и инициатором', exact: true }).click()
  await expect(page.getByRole('status')).toHaveText(/Заявка с датой и инициатором скопирована/)
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('Заявка №000042 от 04.10.2026 от ведущего инженера Тестова Александра Александровича')
  const copyProduct = page.getByRole('button', { name: 'Копировать наименование объекта', exact: true })
  await copyProduct.focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('status')).toHaveCount(1)
  await expect(page.getByRole('status')).toContainText('Наименование объекта скопировано')
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(sample.product_name)
  await expect(page.locator('.request-overview')).not.toContainText('Подразделение')
  await expect(page.locator('.request-entity-head').getByText(sample.manufacturer, { exact: true })).toHaveCount(1)
  await expect(page.getByRole('button', { name: 'Изменить подразделение заявки' })).toHaveCount(0)
})

test('ошибка копирования объясняет ручное действие; пустые значения читаемы', async ({ page }) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { value: { writeText: () => Promise.reject(new Error('denied')) } }))
  await openCard(page, { initiator_name: '  ', initiator_position: null, department: null })
  const heading = page.locator('.request-heading')
  await expect(heading).toContainText('Инициатор не указан')
  await expect(heading).toContainText('Должность не указана')
  await expect(heading).toContainText('Подразделение не указано')
  await page.getByRole('button', { name: 'Копировать наименование объекта', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('Не удалось скопировать. Выделите текст и скопируйте вручную.')
  await page.getByRole('button', { name: 'Закрыть уведомление о копировании' }).click()
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('администратор изменяет подразделение из шапки существующим действием', async ({ page }) => {
  await openCard(page, { can_edit_department: 1 })
  await page.getByRole('button', { name: 'Изменить подразделение заявки' }).click()
  const dialog = page.getByRole('dialog', { name: 'Изменить подразделение', exact: true })
  await dialog.getByRole('textbox', { name: 'Подразделение', exact: true }).fill('Новое тестовое подразделение')
  const posted = page.waitForRequest(request => request.method() === 'POST' && request.url().endsWith('/department'))
  await dialog.getByRole('button', { name: 'Сохранить', exact: true }).click()
  expect((await posted).postDataJSON()).toEqual({ department: 'Новое тестовое подразделение', lockVersion: 1 })
  await expect(dialog).not.toBeVisible()
  await expect(page.locator('.request-heading')).toContainText('Новое тестовое подразделение')
})

test('при неудачном обновлении карточки шапка не предлагает устаревшее редактирование', async ({ page }) => {
  await openCard(page, { can_edit_department: 1 })
  await page.getByRole('button', { name: 'Изменить подразделение заявки' }).click()
  const dialog = page.getByRole('dialog', { name: 'Изменить подразделение', exact: true })
  await dialog.getByRole('textbox', { name: 'Подразделение', exact: true }).fill('Новое подразделение')
  await page.route('**/api/v1/requests/42', route => route.fulfill({ status: 503, json: { message: 'Недоступно' } }))
  await dialog.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(page.getByText('Не удалось загрузить актуальные данные заявки.', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Изменить подразделение заявки' })).toHaveCount(0)
})

test('сводка экспертного заключения показывает все позиции и текстовое количество', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  await openCard(page, {
    status: 'opinion_preparation', can_publish_opinion: 1,
    objects: [
      { productName: 'Направляющая', sampleQuantity: '4 шт по 3 метра' },
      { productName: 'Башмак скольжения', sampleQuantity: '2 комплекта' },
    ],
    sample_quantity: null,
  })
  await page.getByRole('button', { name: 'Написать заключение', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Экспертное заключение', exact: true })
  await expect(dialog.getByRole('rowheader', { name: /Направляющая/ })).toBeVisible()
  await expect(dialog.getByRole('rowheader', { name: /Башмак скольжения/ })).toBeVisible()
  await expect(dialog.getByRole('cell', { name: '4 шт по 3 метра', exact: true })).toBeVisible()
  await expect(dialog.getByRole('cell', { name: '2 комплекта', exact: true })).toBeVisible()
  const copy = dialog.getByRole('button', { name: 'Копировать наименование объекта 1', exact: true })
  await copy.focus()
  await page.keyboard.press('Enter')
  await expect(dialog.getByRole('status')).toContainText('Наименование объекта скопировано')
  await dialog.getByRole('button', { name: 'Закрыть', exact: true }).focus()
  await page.keyboard.press('Shift+Tab')
  await expect(dialog.getByRole('button', { name: 'Закрыть уведомление о копировании' })).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(dialog.getByRole('status')).toHaveCount(0)
  await expect(copy).toBeFocused()
  await expect(dialog).toBeVisible()
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 })
    const summary = await dialog.locator('.opinion-summary').boundingBox()
    const table = await dialog.getByRole('table').boundingBox()
    expect(table.width).toBeGreaterThan(summary.width * 0.95)
  }
})

test('для нескольких объектов одна кнопка копирует наименования через запятую и поставщика', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  await openCard(page, { objects: [
    { productName: 'Объект А', sampleQuantity: '4 шт по 3 метра' },
    { productName: 'Объект Б', sampleQuantity: '2 комплекта' },
  ] })
  expect(await page.locator('.request-objects-chevron').evaluate(element => new DOMMatrix(getComputedStyle(element).transform).b)).toBe(1)
  await page.getByRole('button', { name: 'Показать объекты' }).click()
  expect(await page.locator('.request-objects-chevron').evaluate(element => new DOMMatrix(getComputedStyle(element).transform).b)).toBe(-1)
  const copy = page.getByRole('columnheader').getByRole('button', { name: 'Копировать все образцы и поставщика' })
  await copy.focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('status')).toContainText('Все образцы и поставщик скопированы')
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('Объект А, Объект Б. Поставщик Тестовый поставщик')
  await expect(page.getByRole('button', { name: 'Для документа', exact: true })).toHaveCount(0)
})

test('меню направления остаётся над процессом при свёрнутом списке объектов', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await openCard(page, { can_set_color: 1, objects: [
    { productName: 'Объект А', sampleQuantity: '1' },
    { productName: 'Объект Б', sampleQuantity: '2' },
  ] })
  await page.locator('.request-color-control > summary').click()
  const choices = page.locator('.request-color-menu button')
  await expect(choices).toHaveCount(6)
  for (const choice of await choices.all()) {
    const hit = await choice.evaluate(button => {
      const box = button.getBoundingClientRect()
      const element = document.elementFromPoint(box.x + 24, box.y + box.height / 2)
      return { reachable: button.contains(element), covering: element?.className }
    })
    expect(hit, 'Пункт меню перекрыт другим блоком').toMatchObject({ reachable: true })
  }
  const saved = page.waitForRequest(request => request.method() === 'POST' && request.url().endsWith('/color'))
  await choices.last().click()
  expect((await saved).postDataJSON()).toMatchObject({ color: 'green' })
})

test('объект компактен, правая колонка поднята вверх, копирование соосно тексту', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await openCard(page, { initiator_name: 'Тестов Иван', initiator_position: 'Инженер', department: 'Лаборатория' })
  const row = page.locator('tbody .object-title-row').first()
  const name = await row.locator('.request-object-name').boundingBox()
  const button = await row.getByRole('button').boundingBox()
  expect(await row.locator('.copy-symbol').evaluate(element => getComputedStyle(element).maskImage)).not.toBe('none')
  expect(Math.abs(name.y + name.height / 2 - button.y - button.height / 2)).toBeLessThan(1)
  expect((await page.locator('.request-objects-table tbody tr').boundingBox()).height).toBeLessThanOrEqual(56)
  const status = await page.locator('.request-objects-status .request-status').boundingBox()
  const direction = await page.locator('.request-direction-readonly').boundingBox()
  const objectBlock = await page.locator('.request-object-summary').boundingBox()
  const sidebar = await page.locator('.side-column').boundingBox()
  expect(Math.abs(sidebar.y - objectBlock.y)).toBeLessThan(2)
  expect(objectBlock.x + objectBlock.width).toBeLessThanOrEqual(sidebar.x + 1)
  expect(status.x).toBeGreaterThan(sidebar.x)
  expect(direction.y).toBeGreaterThanOrEqual(status.y + status.height)
  const heading = await page.locator('.request-objects-heading h2').boundingBox()
  expect(name.y - heading.y - heading.height).toBeLessThan(28)
  const initiator = await page.locator('.request-heading-initiator > p').boundingBox()
  const department = await page.locator('.request-heading-department').boundingBox()
  expect(Math.abs(initiator.y - department.y)).toBeLessThan(2)
})

test('на узком экране статус остаётся сверху, маршрут отмечен в процессе, меню направления доступно', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 })
  await openCard(page, { route: 'act', can_set_color: 1 })
  await expect(page.locator('#process-title').getByLabel('Маршрут: Акт испытаний')).toHaveText('Акт')
  const state = await page.locator('.request-sidebar-state').boundingBox()
  const object = await page.locator('.request-object-summary').boundingBox()
  expect(state.y + state.height).toBeLessThanOrEqual(object.y + 1)
  await page.locator('.request-color-control > summary').click()
  const menu = await page.locator('.request-color-menu').boundingBox()
  expect(menu.x).toBeGreaterThanOrEqual(0)
  expect(menu.x + menu.width).toBeLessThanOrEqual(390)
  await expect(page.locator('.request-color-menu button').last()).toBeInViewport()
})

test('существующая подсказка содержит все десять наименований отдельными строками', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 700 })
  await openCard(page)
  const names = Array.from({ length: 10 }, (_, index) => `Позиция ${index + 1}: ${'Длинное наименование '.repeat(15)}`)
  await page.route('**/api/v1/requests?*', route => route.fulfill({ json: {
    items: [{ ...sample, object_names: names, object_count: 10 }],
    total: 1, page: 1, pageSize: 10, pageCount: 1, counts: { active: 1, all: 1, mine: 1 },
  } }))
  await page.getByRole('button', { name: 'Вернуться к списку заявок' }).click()
  const trigger = page.locator('.registry-object-tooltip.app-tooltip')
  await expect(trigger).toHaveAttribute('data-tooltip', names.join('\n'))
  await trigger.hover()
  await expect.poll(() => trigger.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1')
  const presentation = await trigger.evaluate(element => {
    const style = getComputedStyle(element, '::after')
    return { whiteSpace: style.whiteSpace, content: style.content, background: style.backgroundColor, fontSize: style.fontSize }
  })
  expect(presentation.whiteSpace).toBe('pre-line')
  expect(presentation.background).toBe('rgb(22, 39, 115)')
  expect(presentation.fontSize).toBe('10px')
  for (let index = 1; index <= 10; index += 1) expect(presentation.content).toContain(`Позиция ${index}:`)
  await expect(page.locator('.registry-names-tooltip')).toHaveCount(0)
})

test('подсказка открывается снизу при нехватке места сверху и возвращается наверх при прокрутке', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await openCard(page)
  const names = Array.from({ length: 10 }, (_, index) => `Позиция ${index + 1}: направляющая испытательного образца`)
  await page.route('**/api/v1/requests?*', route => route.fulfill({ json: {
    items: [{ ...sample, object_names: names, object_count: 10 }],
    total: 1, page: 1, pageSize: 10, pageCount: 1, counts: { active: 1, all: 1, mine: 1 },
  } }))
  await page.getByRole('button', { name: 'Вернуться к списку заявок' }).click()
  const trigger = page.locator('.registry-object-tooltip.app-tooltip')
  await page.keyboard.press('Tab')
  await trigger.focus()
  await expect.poll(() => trigger.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1')
  const geometry = () => trigger.evaluate(element => {
    const box = element.getBoundingClientRect()
    const scale = box.width / element.offsetWidth
    const style = getComputedStyle(element, '::after')
    const top = parseFloat(style.top) * scale
    const height = (parseFloat(style.height) + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom)) * scale
    return { anchorTop: box.top, anchorBottom: box.bottom, top, bottom: top + height,
      arrow: parseFloat(getComputedStyle(element, '::before').top) * scale, viewport: innerHeight }
  })
  await expect.poll(() => page.locator('.registry').evaluate(element => getComputedStyle(element).opacity)).toBe('1')
  // Fix the anchor near the upper edge to exercise placement independently of registry fixture height.
  await trigger.evaluate(element => { element.style.position = 'fixed'; element.style.top = '40px'; element.style.width = '280px' })
  await page.evaluate(() => window.dispatchEvent(new Event('resize')))
  let box = await geometry()
  expect(box.top).toBeGreaterThan(box.anchorBottom)
  expect(box.bottom).toBeLessThan(box.viewport)
  expect(box.arrow).toBeGreaterThan(box.anchorBottom)
  expect(box.arrow).toBeLessThan(box.top)
  await trigger.evaluate(element => { element.style.top = '600px' })
  await page.evaluate(() => window.dispatchEvent(new Event('scroll')))
  box = await geometry()
  expect(box.top).toBeGreaterThanOrEqual(0)
  expect(box.bottom).toBeLessThan(box.anchorTop)
  expect(box.arrow).toBeLessThan(box.anchorTop)
  await expect(trigger).toHaveAttribute('data-tooltip', names.join('\n'))
})

for (const width of [390, 1440]) {
  test(`длинные реквизиты не обрезаются и не создают переполнение при ширине ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await openCard(page, {
      can_edit_department: 1,
      initiator_name: 'Александров-Константинопольский Александр Александрович',
      initiator_position: 'Ведущий инженер отдела перспективных разработок и испытаний оборудования',
      department: 'Испытательный центр механических и электротехнических исследований подразделения перспективных разработок',
      product_name: 'ДлинноеНаименованиеБезПробелов'.repeat(12),
    })
    expect((await page.locator('.request-heading').boundingBox()).width).toBeGreaterThan(Math.min(280, width * 0.65))
    for (const selector of ['.request-heading', 'tbody .object-title-row']) {
      expect(await page.locator(selector).evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true)
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  })
}

for (const [name, expected, review] of [
  ['Логинова Марина Петровна', 'Логиновой Марины Петровны', false],
  ['Саша Ким', 'Саша Ким', true],
]) {
  test(`копирование склоняет ФИО или предупреждает о неоднозначности: ${name}`, async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    await openCard(page, { initiator_name: name })
    await page.getByRole('button', { name: 'Копировать заявку с датой и инициатором', exact: true }).click()
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(`Заявка №000042 от 04.10.2026 от ведущего инженера ${expected}`)
    await expect(page.locator('.request-heading-initiator')).toContainText(name)
    await expect(page.locator('.request-heading-initiator')).toContainText('Ведущий инженер')
    await expect(page.getByRole('status')).toContainText(review ? 'Проверьте склонение ФИО и должности' : 'Заявка с датой и инициатором скопирована')
  })
}

test('светлое подтверждение копирования исчезает само и не забирает фокус', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  await page.clock.install()
  await openCard(page)
  const copy = page.getByRole('button', { name: 'Копировать заявку с датой и инициатором', exact: true })
  await copy.click()
  const result = page.getByRole('status')
  await expect(result).toHaveClass(/shlz-notification--light/)
  await page.clock.runFor(3000)
  await expect(result).toHaveCount(0)
  await expect(copy).toBeFocused()
})
