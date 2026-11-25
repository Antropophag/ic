import { expect, test } from '@playwright/test'

async function openManagerCard(page) {
  await page.addInitScript(() => localStorage.setItem('ic.dev.userId', '1'))
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname
    const user = { id: 1, displayName: 'Тестовый руководитель', roles: ['ic_manager'] }
    let json = { items: [], categories: [], counts: { active: 0, all: 0, mine: 0 }, total: 0, page: 1, pageSize: 10, pageCount: 0 }
    if (path.endsWith('/auth/me')) json = { user, csrfToken: 'test-token' }
    if (path.endsWith('/dev/users')) json = { items: [user] }
    if (path.endsWith('/executors')) json = { items: [{ id: 7, displayName: 'Тестовый исполнитель' }] }
    if (path.endsWith('/requests/42')) json = {
      item: {
        id: 42, number: 42, created_at: '2026-11-25T10:00:00Z', status: 'registered',
        product_name: 'Тестовый образец', initiator_name: 'Тестовый инициатор',
        department: 'Испытательный центр', manufacturer: 'Тестовый завод', supplier: 'Тестовый поставщик',
        sample_quantity: 2, test_method: 'Программа испытаний', lockVersion: 1, route: 'protocol',
        executor_id: 7, executor_name: 'Тестовый исполнитель',
        can_assign_executor: 1, can_start: 1, can_reject: 1, can_choose_route: 1,
      },
      history: [], comments: [], commentsPage: { hasMore: false }, documents: [],
    }
    return route.fulfill({ json })
  })
  await page.goto('/?request=42')
  await expect(page.locator('.request-action-bar')).toBeVisible()
  await page.evaluate(() => document.fonts.ready)
}

// Rendered geometry catches displacement even when align-items is already center.
async function expectCentered(locator, graphic = false) {
  expect(await locator.count()).toBeGreaterThan(0)
  for (const element of await locator.all()) {
    const result = await element.evaluate((node, measureGraphic) => {
      const box = node.getBoundingClientRect()
      const range = document.createRange()
      const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT)
      const bounds = []
      while (walker.nextNode()) {
        if (!walker.currentNode.textContent.trim()) continue
        range.selectNodeContents(walker.currentNode)
        bounds.push(...range.getClientRects())
      }
      const content = measureGraphic ? node.querySelector('svg').getBoundingClientRect() : {
        top: Math.min(...bounds.map(rect => rect.top)),
        bottom: Math.max(...bounds.map(rect => rect.bottom)),
      }
      return {
        label: node.getAttribute('aria-label') || node.textContent.trim(),
        offset: Math.abs((content.top + content.bottom - box.top - box.bottom) / 2),
        contained: content.top >= box.top && content.bottom <= box.bottom,
      }
    }, graphic)
    expect(result.contained, result.label).toBe(true)
    expect(result.offset, result.label).toBeLessThanOrEqual(0.5)
  }
}

for (const width of [390, 1024, 1440]) {
  test(`текст и иконки компактных контролов по центру при ширине ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 })
    await openManagerCard(page)
    const actions = page.locator('.request-action-bar')
    const buttons = actions.locator('button:not(.request-route-edit)')
    await expect(buttons).toHaveCount(3)
    for (const zoom of [1, 1.1, 1.25]) {
      await page.evaluate(value => document.documentElement.style.setProperty('--portal-zoom', value), String(zoom))
      await expectCentered(buttons)
      await expectCentered(page.locator('.request-text-button:not(.request-route-edit)'))
      await expect(actions.getByRole('combobox', { name: 'Исполнитель ИЦ' })).toHaveValue('7')
      const sizes = await actions.getByRole('group', { name: 'Назначение исполнителя' }).evaluate(row =>
        [...row.querySelectorAll('select,button')].map(node => node.getBoundingClientRect().height))
      expect(Math.abs(sizes[0] - sizes[1])).toBeLessThan(0.1)
    }
    await page.getByRole('button', { name: 'Подробная история' }).click()
    await expect(page.locator('.request-drawer')).toBeVisible()
    await expectCentered(page.locator('.request-drawer-head button'), true)
    await page.locator('.request-drawer-head button').click()
    await expect(page.locator('.request-drawer')).not.toBeVisible()
    await page.getByRole('button', { name: 'Отказать', exact: true }).click()
    await expect(page.getByRole('alertdialog')).toBeVisible()
    await expectCentered(page.locator('.modal-actions button'))
    await expectCentered(page.locator('.modal-head > button'), true)
  })
}

const controlCases = [
  ['Основная кнопка', '<button class="primary">Сохранить</button>'],
  ['Вторичная кнопка', '<button class="secondary">Отмена</button>'],
  ['Недоступная кнопка', '<button class="secondary" disabled>Сохранение…</button>'],
  ['Загрузка', '<label class="primary upload-button">Загрузить отчёт</label>'],
  ['Аккаунт', '<div class="header-account-actions"><button class="secondary">Выйти</button></div>'],
  ['Повтор очереди', '<button class="attention-retry">Повторить</button>'],
  ['Счётчик очереди', '<span class="attention-count">12</span>'],
  ['Очистка фильтра', '<div class="registry"><button class="toolbar-clear">Сбросить</button></div>'],
  ['Пустой реестр', '<div class="registry"><button class="primary empty-action">Новая заявка</button></div>'],
  ['Создание заявки', '<div class="registry"><button class="primary tabs-cta">Новая заявка</button></div>'],
  ['Ошибка реестра', '<div class="registry-error"><button>Повторить</button></div>'],
  ['Счётчик вкладки', '<div class="registry"><span class="tab-count">12</span></div>'],
  ['Индикатор', '<span class="badge green">Работает</span>'],
  ['Статус реестра', '<div class="registry"><span class="shlz-status request-status"><span class="request-status-text">Заявка зарегистрирована</span></span></div>'],
  ['Статус карточки', '<span class="shlz-status request-status"><span class="request-status-text">Заявка зарегистрирована</span></span>'],
  ['Админка', '<div class="admin-heading"><button class="secondary">Обновить</button></div>'],
  ['Сервис', '<div class="admin-service-head"><span class="badge green">Работает</span></div>'],
  ['Роль', '<span class="role-chip">Руководитель ИЦ</span>'],
  ['Номер страницы', '<div class="registry"><div class="pagination"><button><span class="pagination-page-number">2</span></button></div></div>'],
  ['Размер страницы', '<div class="registry"><div class="pagination"><button class="page-size-option page-size-option--50">50</button></div></div>'],
  ['Количество документов', '<div class="request-documents-head"><span class="request-document-count">12</span></div>'],
  ['SHLZ', '<button class="shlz-button">Сохранить</button>'],
  ['SHLZ small', '<button class="shlz-button shlz-button--sm">Применить</button>'],
  ['SHLZ xs', '<button class="shlz-button shlz-button--xs">Удалить</button>'],
]

test('семейства однострочных контролов сохраняют центр при масштабировании', async ({ page }) => {
  await openManagerCard(page)
  await page.evaluate(cases => {
    const gallery = document.createElement('section')
    gallery.id = 'alignment-gallery'
    for (const [name, markup] of cases) {
      const row = document.createElement('div')
      row.setAttribute('aria-label', name)
      row.innerHTML = markup
      row.style.cssText = 'display:flex;align-items:center;min-height:64px;padding:8px'
      gallery.append(row)
    }
    document.querySelector('#app').replaceChildren(gallery)
  }, controlCases)
  await page.evaluate(() => document.fonts.ready)
  for (const zoom of [1, 1.1, 1.25]) {
    await page.evaluate(value => document.documentElement.style.setProperty('--portal-zoom', value), String(zoom))
    for (const [name] of controlCases) {
      const row = page.locator(`#alignment-gallery > [aria-label="${name}"]`)
      await expectCentered(row.locator('button,label,.attention-count,.tab-count,.badge,.request-status,.role-chip,.request-document-count'))
    }
  }
})

const fieldCases = [
  ['Исполнитель', '<div class="request-action-bar"><select><option>Исполнитель ИЦ</option></select></div>'],
  ['Поиск', '<div class="registry"><label class="search"><input type="search" value="Тестовый образец"></label></div>'],
  ['Статус', '<div class="registry"><div class="status-filter"><select><option>Заявка в работе</option></select></div></div>'],
  ['Форма', '<div class="modal"><div class="form-grid"><input value="Тестовый образец"></div></div>'],
  ['Поле диалога', '<div class="modal-body"><label><input value="Тестовый образец"></label></div>'],
  ['Админ фильтр', '<div class="admin-filters"><input value="Тестовый образец"></div>'],
  ['Админ выбор', '<div class="admin-create-user"><select><option>Руководитель ИЦ</option></select></div>'],
  ['Назначение роли', '<div class="role-assign"><select><option>Руководитель ИЦ</option></select></div>'],
  ['Вход', '<div class="auth-card"><input value="Тестовый образец"></div>'],
  ['Комментарий', '<div class="request-page"><div class="comment-input"><input value="Тестовый образец"></div></div>'],
]

test('текст нативных полей визуально центрирован и не обрезан', async ({ page }) => {
  await openManagerCard(page)
  for (const [name, markup] of fieldCases) {
    await page.evaluate(html => {
      const fixture = document.createElement('div')
      fixture.id = 'field-fixture'
      fixture.innerHTML = html
      document.querySelector('#app').replaceChildren(fixture)
    }, markup)
    const field = page.locator('#field-fixture input,#field-fixture select')
    const color = await field.evaluate(node => getComputedStyle(node).color.match(/\d+/g).slice(0, 3).map(Number))
    // Native input/select text lives in the UA shadow tree, outside DOM Range.
    // Inspect its painted pixels, excluding borders and the dropdown arrow.
    const png = await field.screenshot()
    const ink = await page.evaluate(async ({ data, foreground }) => {
      const image = new Image()
      image.src = `data:image/png;base64,${data}`
      await image.decode()
      const canvas = document.createElement('canvas')
      canvas.width = image.width
      canvas.height = image.height
      const context = canvas.getContext('2d')
      context.drawImage(image, 0, 0)
      const { data: pixels } = context.getImageData(0, 0, canvas.width, canvas.height)
      const rows = []
      for (let y = 2; y < image.height - 2; y += 1) {
        for (let x = 8; x < image.width - 40; x += 1) {
          const offset = (y * image.width + x) * 4
          const distance = foreground.reduce((sum, channel, index) => sum + Math.abs(channel - pixels[offset + index]), 0)
          if (pixels[offset + 3] > 200 && distance < 100) { rows.push(y); break }
        }
      }
      return { count: rows.length, top: rows[0], bottom: image.height - 1 - rows.at(-1) }
    }, { data: png.toString('base64'), foreground: color })
    expect(ink.count, name).toBeGreaterThan(0)
    expect(ink.top, name).toBeGreaterThan(2)
    expect(ink.bottom, name).toBeGreaterThan(2)
    // Glyph ascenders/descenders vary by label; allow two painted pixels.
    expect(Math.abs(ink.top - ink.bottom), name).toBeLessThanOrEqual(4)
  }
})

test('кнопка шаблона документа учитывает уменьшение движения', async ({ page }) => {
  await openManagerCard(page)
  await page.evaluate(() => {
    document.querySelector('#app').innerHTML = '<button class="request-document-group-action" aria-label="Шаблон"><svg width="16" height="16"><path class="magic-wand-spark magic-wand-spark--large" d="M1 1L8 8" /></svg></button>'
  })
  const button = page.getByRole('button', { name: 'Шаблон' })
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await button.hover()
  await expect(button).toHaveCSS('transform', 'none')
  await expect(button).toHaveCSS('transition-duration', '0s')
  await expect(button.locator('path')).toHaveCSS('animation-name', 'none')
  await page.mouse.down()
  await expect(button).toHaveCSS('transform', 'none')
  await page.mouse.up()
  await page.emulateMedia({ reducedMotion: 'no-preference' })
  await expect(button.locator('path')).toHaveCSS('animation-name', 'request-document-twinkle')
  await expect(button).not.toHaveCSS('transform', 'none')
})
