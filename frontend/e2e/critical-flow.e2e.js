import { expect, request as playwrightRequest, test } from '@playwright/test'

const identityHeader = process.env.E2E_IDENTITY_HEADER || 'X-Test-User-ID'

async function apiFor(baseURL, userId) {
  const bootstrap = await playwrightRequest.newContext({
    baseURL,
    extraHTTPHeaders: { [identityHeader]: String(userId) },
  })
  const me = await bootstrap.get('/api/v1/auth/me')
  expect(me.ok(), await me.text()).toBe(true)
  const { csrfToken } = await me.json()
  const storageState = await bootstrap.storageState()
  await bootstrap.dispose()

  const context = await playwrightRequest.newContext({
    baseURL,
    storageState,
    extraHTTPHeaders: {
      [identityHeader]: String(userId),
      'X-CSRF-Token': csrfToken,
    },
  })
  return {
    get: (...args) => context.get(...args),
    post: (path, options = {}) => context.post(path, {
      ...options,
      headers: { ...options.headers, 'Idempotency-Key': options.headers?.['Idempotency-Key'] || crypto.randomUUID() },
    }),
    dispose: () => context.dispose(),
  }
}

async function expectOk(response) {
  expect(response.ok(), await response.text()).toBe(true)
  return response.json()
}

async function useTestIdentity(page, userId) {
  await page.route('**/api/**', async route => {
    await route.continue({ headers: { ...route.request().headers(), [identityHeader]: String(userId) } })
  })
}

for (const label of ['Согласовано', 'Не согласовано']) {
test(`заявка завершается после решения СБ «${label}»`, async ({ page, baseURL }) => {
  const marker = `E2E-${Date.now()}`
  const initiator = await apiFor(baseURL, 3)
  const manager = await apiFor(baseURL, 1)
  const executor = await apiFor(baseURL, 2)
  const expert = await apiFor(baseURL, 4)

  const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
    productName: marker,
    manufacturer: 'Тестовый производитель',
    supplier: 'Тестовый поставщик',
    sampleQuantity: 1,
    testMethod: 'Критический E2E-сценарий',
  } }))
  const requestId = created.id
  expect(created).toMatchObject({
    id: requestId,
    status: 'registered',
  })

  const persisted = await expectOk(await initiator.get(`/api/v1/requests/${requestId}`))
  expect(persisted.item).toMatchObject({
    id: requestId,
    product_name: marker,
    status: 'registered',
    lockVersion: 1,
  })

  await expectOk(await manager.post(`/api/v1/requests/${requestId}/route`, { data: { route: 'protocol', lockVersion: 1 } }))
  const assigned = await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, {
    data: { executorId: 2, lockVersion: 2 },
  }))
  expect(assigned).toMatchObject({ executorId: 2, lockVersion: 3 })

  const started = await expectOk(await manager.post(`/api/v1/requests/${requestId}/start`, {
    data: { lockVersion: 3 },
  }))
  expect(started).toMatchObject({ status: 'in_progress', lockVersion: 4 })
  await expectOk(await executor.post(`/api/v1/requests/${requestId}/report`, {
    multipart: { file: { name: 'e2e-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
  }))
  await expectOk(await expert.post(`/api/v1/requests/${requestId}/expert/claim`, {
    data: { lockVersion: 5 },
  }))
  await expectOk(await expert.post(`/api/v1/requests/${requestId}/opinion`, {
    data: { body: 'Образец соответствует требованиям критического E2E-сценария.', lockVersion: 6 },
  }))

  await page.route('**/api/**', async route => {
    await route.continue({ headers: { ...route.request().headers(), [identityHeader]: '5' } })
  })
  await page.goto('/')
  await page.getByRole('row').filter({ hasText: marker }).click()
  await expect(page.locator('.request-objects-status').getByText('Контроль СБ', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Лента', exact: true })).toBeVisible()
  await expect(page.getByText('Экспертное заключение опубликовано', { exact: false })).toBeVisible()
  const securityMarkIcon = page.locator('.side-column .security-mark-icon')
  await expect(securityMarkIcon).toBeVisible()
  await expect(securityMarkIcon).toHaveCSS('display', 'flex')
  await expect(securityMarkIcon).toHaveCSS('align-items', 'center')
  await expect(securityMarkIcon).toHaveCSS('margin-bottom', '0px')
  await page.getByRole('button', { name: label, exact: true }).click()
  await page.getByRole('alertdialog').getByRole('button', { name: label, exact: true }).click()
  await expect(page.locator('.request-objects-status').getByText('Заявка выполнена', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: label, exact: true })).toHaveCount(0)

  await expect(page.getByRole('button', { name: 'Исправить решение СБ', exact: true })).toHaveCount(0)
  await page.unroute('**/api/**')
  await useTestIdentity(page, 6)
  await page.reload()
  await page.getByRole('button', { name: 'Исправить решение СБ', exact: true }).click()
  const correction = page.getByRole('dialog', { name: 'Исправить решение СБ', exact: true })
  await expect(correction.getByRole('button', { name: 'Сохранить исправление' })).toBeDisabled()
  const corrected = label === 'Согласовано' ? 'decline' : 'approve'
  await correction.getByLabel('Новое решение').selectOption(corrected)
  await correction.getByLabel('Причина исправления').fill('Ошибочно выбран результат СБ')
  await correction.getByLabel('Номер или ссылка на обращение в ИТ').fill('IT-360')
  await correction.getByRole('button', { name: 'Сохранить исправление' }).click()
  await expect(correction).toBeHidden()
  await expect(page.locator('.request-security-status')).toContainText(corrected === 'approve' ? 'Согласовано' : 'Не согласовано')
  await expect(page.locator('.request-objects-status')).toContainText('Заявка выполнена')
  await expect(page.getByText(/Исправлено решение СБ:.*IT-360/)).toBeVisible()
  await page.getByRole('button', { name: 'Подробная история' }).click()
  const history = page.getByRole('dialog', { name: 'История процесса' })
  await expect(history).toContainText(label === 'Согласовано' ? 'СБ: согласовано, заявка выполнена' : 'СБ: не согласовано, заявка выполнена')
  await expect(history).toContainText('IT-360')
  await page.getByRole('button', { name: 'Закрыть историю' }).click()

  const administrator = await apiFor(baseURL, 6)
  try {
    const before = await expectOk(await administrator.get(`/api/v1/requests/${requestId}`))
    const data = { decision: label === 'Согласовано' ? 'approve' : 'decline', reason: 'Повторная проверка', ticketReference: 'IT-361', lockVersion: before.item.lockVersion }
    const keys = [crypto.randomUUID(), crypto.randomUUID()]
    const results = await Promise.all([
      administrator.post(`/api/v1/requests/${requestId}/correct-security-decision`, { data, headers: { 'Idempotency-Key': keys[0] } }),
      administrator.post(`/api/v1/requests/${requestId}/correct-security-decision`, { data, headers: { 'Idempotency-Key': keys[1] } }),
    ])
    expect(results.map(response => response.status()).sort()).toEqual([200, 409])
    {
      const key = keys[results.findIndex(response => response.status() === 200)]
      const replay = await administrator.post(`/api/v1/requests/${requestId}/correct-security-decision`, { data, headers: { 'Idempotency-Key': key } })
      expect(replay.status()).toBe(200)
      expect(replay.headers()['idempotency-replayed']).toBe('true')
    }
    const after = await expectOk(await administrator.get(`/api/v1/requests/${requestId}`))
    expect(after.item.lockVersion).toBe(before.item.lockVersion + 1)
    expect(after.history.filter(entry => entry.action === 'correct_security_decision')).toHaveLength(2)
  } finally {
    await administrator.dispose()
  }

  await Promise.all([initiator.dispose(), manager.dispose(), executor.dispose(), expert.dispose()])
})

}

test('заявка перемещается между персональными очередями ролей', async ({ page, context, baseURL }) => {
  const marker = `E2E-queue-${Date.now()}`
  let initiator
  let manager
  let executor
  let expert

  try {
    initiator = await apiFor(baseURL, 3)
    manager = await apiFor(baseURL, 1)
    executor = await apiFor(baseURL, 2)
    expert = await apiFor(baseURL, 4)
    const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
      productName: marker,
      manufacturer: 'Тестовый производитель',
      supplier: 'Тестовый поставщик',
      sampleQuantity: 1,
      testMethod: 'Перемещение между очередями — E2E',
    } }))
    const requestId = created.id
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/route`, { data: { route: 'protocol', lockVersion: 1 } }))

    await useTestIdentity(page, 1)
    await page.goto('/')
    const dashboardHelp = page.getByRole('button', { name: 'Инструкция по заявкам, требующим внимания' })
    await expect(dashboardHelp).toBeVisible()
    await expect(dashboardHelp).toHaveCSS('position', 'static')
    await dashboardHelp.click()
    const dashboardHelpDialog = page.getByRole('dialog', { name: 'Справка' })
    await expect(dashboardHelpDialog).toContainText('Заявки, требующие внимания')
    await page.getByRole('button', { name: 'Закрыть справку' }).click()
    await expect(dashboardHelpDialog).toBeHidden()
    const managerQueue = page.getByRole('button', { name: /Назначить исполнителя/ })
    await managerQueue.click()
    await page.getByRole('row').filter({ hasText: marker }).click()

    await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, {
      data: { executorId: 2, lockVersion: 2 },
    }))
    await Promise.all([
      page.waitForResponse(response => response.url().includes('/api/v1/requests/dashboard') && response.ok()),
      page.getByTitle('На главную').click(),
    ])
    // Other parallel scenarios can register requests, so assert this request's
    // membership instead of a delta in the shared global counter.
    const remainingAssignments = await expectOk(await manager.get(`/api/v1/requests?tab=all&attention=assign_executor&query=${encodeURIComponent(marker)}`))
    expect(remainingAssignments.total).toBe(0)
    if (await managerQueue.count()) {
      await expect(managerQueue).toHaveAttribute('aria-pressed', 'true')
      await expect(page.getByRole('row').filter({ hasText: marker })).toHaveCount(0)
    }

    const executorPage = await context.newPage()
    await useTestIdentity(executorPage, 2)
    await executorPage.goto('/')
    await executorPage.getByRole('button', { name: /Начать или возобновить работы/ }).click()
    await expect(executorPage.getByRole('row').filter({ hasText: marker })).toBeVisible()
    await expectOk(await executor.post(`/api/v1/requests/${requestId}/start`, {
      data: { lockVersion: 3 },
    }))
    await executorPage.reload()
    await executorPage.getByRole('button', { name: /Загрузить отчёт/ }).click()
    await expect(executorPage.getByRole('row').filter({ hasText: marker })).toBeVisible()
    await expectOk(await executor.post(`/api/v1/requests/${requestId}/report`, {
      multipart: { file: { name: 'queue-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
    }))

    const expertPage = await context.newPage()
    await useTestIdentity(expertPage, 4)
    await expertPage.goto('/')
    await expertPage.getByRole('button', { name: /Взять заявку на экспертизу/ }).click()
    await expect(expertPage.getByRole('row').filter({ hasText: marker })).toBeVisible()
    await expectOk(await expert.post(`/api/v1/requests/${requestId}/expert/claim`, {
      data: { lockVersion: 5 },
    }))
    await expertPage.reload()
    await expertPage.getByRole('button', { name: /Подготовить заключение/ }).click()
    await expect(expertPage.getByRole('row').filter({ hasText: marker })).toBeVisible()
    await expectOk(await expert.post(`/api/v1/requests/${requestId}/opinion`, {
      data: { body: 'Заключение для проверки перемещения между очередями.', lockVersion: 6 },
    }))

    const securityPage = await context.newPage()
    await useTestIdentity(securityPage, 5)
    await securityPage.goto('/')
    const securityQueue = securityPage.getByRole('button', { name: /Согласовать протокол испытаний/ })
    const securityCountBefore = Number(await securityQueue.locator('.attention-count').innerText())
    await securityQueue.click()
    await expect(securityPage.getByRole('row').filter({ hasText: marker })).toBeVisible()
    await securityPage.getByRole('row').filter({ hasText: marker }).click()
    await securityPage.getByRole('button', { name: 'Согласовано', exact: true }).click()
    await securityPage.getByRole('alertdialog').getByRole('button', { name: 'Согласовано', exact: true }).click()
    await expect(securityPage.getByRole('button', { name: 'Согласовано', exact: true })).toHaveCount(0)
    await Promise.all([
      securityPage.waitForResponse(response => response.url().includes('/api/v1/requests/dashboard') && response.ok()),
      securityPage.getByTitle('На главную').click(),
    ])
    if (securityCountBefore === 1) {
      await expect(securityQueue).toHaveCount(0)
    } else {
      await expect(securityQueue.locator('.attention-count')).toHaveText(String(securityCountBefore - 1))
      await expect(securityQueue).toHaveAttribute('aria-pressed', 'true')
      await expect(securityPage.getByRole('row').filter({ hasText: marker })).toHaveCount(0)
    }
  } finally {
    await Promise.all([initiator, manager, executor, expert].filter(Boolean).map(api => api.dispose()))
  }
})

test('комментарий, оставленный при создании заявки, появляется в её ленте', async ({ page }) => {
  const marker = `E2E-comment-${Date.now()}`
  const comment = 'Срочно, испытания нужны до конца недели.'

  await useTestIdentity(page, 3)
  await page.goto('/')
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  await page.getByPlaceholder('Укажите наименование и тип продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Тестовый производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Тестовый поставщик')
  await page.getByPlaceholder('Обозначьте объём и метод испытаний: укажите пункты документов, содержащих требования к образцу, а также метод или методику испытаний.').fill('Комментарий при создании — E2E')
  await page.getByPlaceholder('Добавьте пояснение к заявке').fill(comment)
  await page.getByRole('button', { name: 'Создать заявку' }).click()

  await expect(page.getByRole('heading', { name: /^Заявка №\d+ от \d{1,2}\.\d{1,2}\.\d{4}$/ })).toBeVisible()
  await expect(page.locator('#request-comments').getByText(comment, { exact: true })).toBeVisible()
})

test('черновик новой заявки восстанавливается и удаляется после создания', async ({ page }) => {
  const marker = `E2E-draft-${Date.now()}`
  await useTestIdentity(page, 3)
  await page.goto('/')
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  await page.getByPlaceholder('Укажите наименование и тип продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Черновой производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Черновой поставщик')
  await page.getByPlaceholder('Обозначьте объём и метод испытаний: укажите пункты документов, содержащих требования к образцу, а также метод или методику испытаний.').fill('Проверка восстановления')

  await expect.poll(() => page.evaluate(key => localStorage.getItem(key), 'ic.application-create-draft.v1.3'))
    .toContain(marker)
  await page.reload()
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  await expect(page.getByRole('status')).toHaveText('Черновик восстановлен.')
  await expect(page.getByPlaceholder('Укажите наименование и тип продукции')).toHaveValue(marker)
  await expect(page.getByPlaceholder('Наименование производителя')).toHaveValue('Черновой производитель')
  await expect(page.getByPlaceholder('Наименование поставщика')).toHaveValue('Черновой поставщик')
  await page.getByRole('button', { name: 'Создать заявку' }).click()

  await expect(page.getByRole('heading', { name: /^Заявка №\d+ от \d{1,2}\.\d{1,2}\.\d{4}$/ })).toBeVisible()
  await expect.poll(() => page.evaluate(key => localStorage.getItem(key), 'ic.application-create-draft.v1.3'))
    .toBeNull()
  await page.getByTitle('На главную').click()
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  await expect(page.getByPlaceholder('Укажите наименование и тип продукции')).toHaveValue('')
  await expect(page.getByRole('status')).toHaveCount(0)
})

test('реестр показывает индикаторы последнего комментария и отчёта', async ({ page, baseURL }) => {
  const marker = `E2E-indicators-${Date.now()}`
  const initiator = await apiFor(baseURL, 3)
  const manager = await apiFor(baseURL, 1)
  const executor = await apiFor(baseURL, 2)

  const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
    productName: marker,
    manufacturer: 'Тестовый производитель',
    supplier: 'Тестовый поставщик',
    sampleQuantity: 1,
    testMethod: 'Индикаторы реестра — E2E',
  } }))
  const requestId = created.id
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/route`, { data: { route: 'protocol', lockVersion: 1 } }))
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, {
    data: { executorId: 2, lockVersion: 2 },
  }))
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/start`, {
    data: { lockVersion: 3 },
  }))
  await expectOk(await executor.post(`/api/v1/requests/${requestId}/report`, {
    multipart: { file: { name: 'e2e-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
  }))
  const commentText = 'Отчёт направлен на согласование'
  await expectOk(await executor.post(`/api/v1/requests/${requestId}/comments`, {
    data: { body: commentText },
  }))

  await page.route('**/api/**', async route => {
    await route.continue({ headers: { ...route.request().headers(), [identityHeader]: '2' } })
  })
  await page.goto('/')
  const row = page.getByRole('row').filter({ hasText: marker })

  // Клик по значку отчёта скачивает файл, а не открывает карточку заявки.
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    row.getByRole('button', { name: 'Скачать отчёт испытаний' }).click(),
  ])
  expect(download.suggestedFilename()).toBe('e2e-report.pdf')
  await expect(page.getByRole('heading', { name: /^Заявка №\d+ от / })).toHaveCount(0)

  await row.getByRole('button', { name: /Последний комментарий/ }).click()
  const commentDialog = page.getByRole('dialog')
  await expect(commentDialog.getByText(commentText, { exact: true })).toBeVisible()
  await commentDialog.getByRole('button', { name: 'Закрыть' }).last().click()
  await expect(commentDialog).toHaveCount(0)

  await Promise.all([initiator.dispose(), manager.dispose(), executor.dispose()])
})

test('кнопка «назад» браузера возвращает из карточки заявки в реестр', async ({ page }) => {
  const marker = `E2E-back-${Date.now()}`
  const errors = []
  page.on('pageerror', error => errors.push(error.message))

  await useTestIdentity(page, 3)
  await page.goto('/')
  await page.getByRole('button', { name: 'Новая заявка' }).click()
  await page.getByPlaceholder('Укажите наименование и тип продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Тестовый производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Тестовый поставщик')
  await page.getByPlaceholder('Обозначьте объём и метод испытаний: укажите пункты документов, содержащих требования к образцу, а также метод или методику испытаний.').fill('Кнопка назад браузера — E2E')
  await page.getByRole('button', { name: 'Создать заявку' }).click()

  const heading = page.getByRole('heading', { name: /^Заявка №\d+ от \d{1,2}\.\d{1,2}\.\d{4}$/ })
  await expect(heading).toBeVisible()
  expect(page.url()).toContain('request=')

  await page.goBack()
  await expect(page.getByPlaceholder('Поиск по заявкам')).toBeVisible()
  expect(page.url()).not.toContain('request=')

  await page.goForward()
  await expect(heading).toBeVisible()

  expect(errors).toEqual([])
})

test('администратор управляет ролями и возвращается в реестр без ошибок рендера', async ({ page }) => {
  // Регрессия: v-else детального экрана заявки был привязан не к тому
  // v-if и срабатывал, когда открыт экран администрирования (selected
  // оставался null) — рендер падал на обращении к полю несуществующей
  // заявки. Экран администрирования должен открываться и закрываться,
  // не ломая реестр заявок.
  const errors = []
  page.on('pageerror', error => errors.push(error.message))

  await useTestIdentity(page, 6)
  await page.goto('/')
  await page.getByRole('button', { name: 'Администрирование' }).click()
  await expect(page.getByRole('tab', { name: 'Обзор' })).toHaveAttribute('aria-selected', 'true')
  await expect(page.locator('.admin-service-head').getByText('База данных', { exact: true })).toBeVisible()
  await expect(page.locator('.admin-service-head').getByText('Файловое хранилище', { exact: true })).toBeVisible()
  await page.getByRole('tab', { name: 'Пользователи и роли' }).click()
  await expect(page.getByRole('tab', { name: 'Пользователи и роли' })).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByRole('cell', { name: 'ЕВ Елена Васильева', exact: true })).toBeVisible()
  await expect(page.getByRole('columnheader', { name: 'Использование' })).toBeVisible()
  await expect(page.getByText('«Активен» означает запрос к порталу за последние 10 минут.')).toBeVisible()
  await expect(page.locator('.admin-usage-active').first()).toBeVisible()

  await page.getByRole('button', { name: 'К реестру' }).click()
  await expect(page.getByRole('tab', { name: 'Пользователи и роли' })).toHaveCount(0)
  await expect(page.getByPlaceholder('Поиск по заявкам')).toBeVisible()

  expect(errors).toEqual([])
})

test('администратор исправляет историческое подразделение одной заявки', async ({ page, baseURL }) => {
  const initiator = await apiFor(baseURL, 3)
  try {
    const first = await expectOk(await initiator.post('/api/v1/requests', { data: {
      productName: `E2E-department-first-${Date.now()}`,
      manufacturer: 'Тестовый производитель',
      supplier: 'Тестовый поставщик',
      sampleQuantity: 1,
      testMethod: 'Проверка snapshot подразделения',
    } }))
    const second = await expectOk(await initiator.post('/api/v1/requests', { data: {
      productName: `E2E-department-second-${Date.now()}`,
      manufacturer: 'Тестовый производитель',
      supplier: 'Тестовый поставщик',
      sampleQuantity: 1,
      testMethod: 'Проверка изоляции snapshot',
    } }))

    await useTestIdentity(page, 6)
    await page.goto(`/?request=${first.id}`)
    const departmentFact = page.locator('.request-heading-department')
    await expect(departmentFact.locator('p')).toHaveText('Тестовое подразделение')
    await page.getByRole('button', { name: 'Изменить подразделение заявки', exact: true }).click()
    await page.getByLabel('Подразделение', { exact: true }).fill('Подразделение C')
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
    await expect(departmentFact.locator('p')).toHaveText('Подразделение C')
    await expect(page.getByText('Подразделение заявки изменено: Подразделение C', { exact: false })).toBeVisible()

    const unchanged = await expectOk(await initiator.get(`/api/v1/requests/${second.id}`))
    expect(unchanged.item.department).toBe('Тестовое подразделение')
  } finally {
    await initiator.dispose()
  }
})

test('конфликт изменения подразделения обновляет карточку и отключает устаревшее действие', async ({ page, baseURL }) => {
  const initiator = await apiFor(baseURL, 3)
  const administrator = await apiFor(baseURL, 6)
  try {
    const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
      productName: `E2E-department-conflict-${Date.now()}`,
      manufacturer: 'Тестовый производитель',
      supplier: 'Тестовый поставщик',
      sampleQuantity: 1,
      testMethod: 'Проверка optimistic locking подразделения',
    } }))

    await useTestIdentity(page, 6)
    await page.goto(`/?request=${created.id}`)
    await page.getByRole('button', { name: 'Изменить подразделение заявки', exact: true }).click()
    await page.getByLabel('Подразделение', { exact: true }).fill('Устаревшее изменение')

    await expectOk(await administrator.post(`/api/v1/requests/${created.id}/department`, {
      data: { department: 'Параллельное изменение', lockVersion: created.lock_version },
    }))

    let releaseRefresh
    const refreshReleased = new Promise(resolve => { releaseRefresh = resolve })
    let refreshStarted
    const refreshObserved = new Promise(resolve => { refreshStarted = resolve })
    await page.route(`**/api/v1/requests/${created.id}`, async route => {
      if (route.request().method() === 'GET') {
        refreshStarted()
        await refreshReleased
      }
      await route.continue({ headers: { ...route.request().headers(), [identityHeader]: '6' } })
    })

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
    await refreshObserved
    await expect(page.getByRole('button', { name: 'Изменить подразделение заявки', exact: true })).toHaveCount(0)
    releaseRefresh()

    await expect(page.getByText('Заявка уже изменена. Данные обновлены', { exact: false })).toBeVisible()
    await expect(page.locator('.request-heading-department').locator('p'))
      .toHaveText('Параллельное изменение')
  } finally {
    await Promise.all([initiator.dispose(), administrator.dispose()])
  }
})

test('администратор читает журналы действий и уведомлений и открывает связанную заявку', async ({ page, baseURL }) => {
  const marker = `E2E-admin-logs-${Date.now()}`
  const contexts = []
  try {
    const initiator = await apiFor(baseURL, 3)
    contexts.push(initiator)
    const manager = await apiFor(baseURL, 1)
    contexts.push(manager)
    const admin = await apiFor(baseURL, 6)
    contexts.push(admin)
  const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
    productName: marker,
    manufacturer: 'Тестовый производитель',
    supplier: 'Тестовый поставщик',
    sampleQuantity: 1,
    testMethod: 'Read-only admin logs E2E',
  } }))
  await expectOk(await manager.post(`/api/v1/requests/${created.id}/route`, { data: { route: 'protocol', lockVersion: 1 } }))
  await expectOk(await manager.post(`/api/v1/requests/${created.id}/executor`, {
    data: { executorId: 2, lockVersion: 2 },
  }))
  await expect.poll(async () => {
    const notifications = await expectOk(await admin.get(`/api/v1/admin/notifications?requestId=${created.id}`))
    return notifications.items[0]?.status
  }).toBe('sent')
  const statusLabel = 'Отправлено'

  await useTestIdentity(page, 6)
  await page.goto('/')
  await page.getByRole('button', { name: 'Администрирование' }).click()
  await page.getByRole('tab', { name: 'Журнал действий' }).click()
  await page.getByRole('spinbutton', { name: 'Заявка' }).fill(String(created.id))
  await page.getByRole('button', { name: 'Применить' }).click()
  await expect(page.getByRole('cell', { name: 'Назначен исполнитель' }).first()).toBeVisible()
  await page.getByRole('cell', { name: 'Назначен исполнитель' }).first().click()
  await expect(page.getByText('request.executor_assigned')).toBeVisible()
  await page.getByRole('button', { name: 'Закрыть' }).click()
  await page.getByRole('button', { name: new RegExp(`Заявка №`) }).first().press('Enter')
  await expect(page.getByRole('rowheader', { name: marker, exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Администрирование' }).click()
  await page.getByRole('tab', { name: 'Уведомления' }).click()
  await page.getByRole('spinbutton', { name: 'Заявка' }).fill(String(created.id))
  await page.getByRole('tabpanel', { name: 'Уведомления' }).getByLabel('Статус').selectOption({ label: statusLabel })
  await page.getByRole('button', { name: 'Применить' }).click()
  await expect(page.locator('.admin-log-table .badge', { hasText: statusLabel }).first()).toBeVisible()
  await expect(page.getByText('SECRET BODY')).toHaveCount(0)
  await page.getByRole('button', { name: new RegExp(`Заявка №`) }).first().press('Enter')
  await expect(page.getByRole('rowheader', { name: marker, exact: false })).toBeVisible()
  } finally {
    await Promise.allSettled(contexts.map(context => context.dispose()))
  }
})

for (const managerId of [1, 7]) {
  test(`маршрут Акт выбирает и завершает руководитель ${managerId}`, async ({ page, baseURL }) => {
    const initiator = await apiFor(baseURL, 3)
    const manager = await apiFor(baseURL, managerId)
    const executor = await apiFor(baseURL, 2)
    try {
      const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
        productName: `Акт E2E ${managerId} ${Date.now()}`, manufacturer: 'Завод', supplier: 'Поставщик', sampleQuantity: 1, testMethod: 'Программа испытаний',
      } }))
      const requestId = created.id
      expect((await manager.post(`/api/v1/requests/${requestId}/route`, { data: { route: true, lockVersion: 1 } })).status()).toBe(422)
      await useTestIdentity(page, managerId)
      await page.goto(`/?request=${requestId}`)
      await expect(page.getByLabel('Исполнитель ИЦ', { exact: true })).toHaveCount(0)
      await page.getByLabel('Маршрут', { exact: true }).selectOption('act')
      await page.getByRole('button', { name: 'Сохранить маршрут', exact: true }).click()
      await expect(page.getByRole('button', { name: 'Сохранить маршрут', exact: true })).toHaveCount(0)
      await expect(page.getByLabel('Исполнитель ИЦ', { exact: true })).toBeVisible()
      await expect(page.locator('.request-objects-status').getByText('Заявка зарегистрирована', { exact: true })).toBeVisible()
      let details = await expectOk(await manager.get(`/api/v1/requests/${requestId}`))
      expect(details.item.route).toBe('act')
      await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, { data: { executorId: 2, lockVersion: details.item.lockVersion } }))
      details = await expectOk(await manager.get(`/api/v1/requests/${requestId}`))
      await expectOk(await executor.post(`/api/v1/requests/${requestId}/start`, { data: { lockVersion: details.item.lockVersion } }))
      details = await expectOk(await manager.get(`/api/v1/requests/${requestId}`))
      expect((await manager.post(`/api/v1/requests/${requestId}/complete-act`, { data: { lockVersion: details.item.lockVersion } })).status()).toBe(403)
      await expectOk(await executor.post(`/api/v1/requests/${requestId}/report`, {
        multipart: { file: { name: 'act.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
      }))
      await page.reload()
      await expect(page.locator('.process-timeline li')).toHaveCount(3)
      await expect(page.getByRole('heading', { name: 'Контроль СБ', exact: true })).toHaveCount(0)
      await page.getByRole('button', { name: 'Завершить заявку', exact: true }).click()
      await page.getByRole('alertdialog').getByRole('button', { name: 'Завершить заявку', exact: true }).click()
      await expect(page.locator('.request-objects-status').getByText('Заявка выполнена', { exact: true })).toBeVisible()
      details = await expectOk(await initiator.get(`/api/v1/requests/${requestId}`))
      expect(details.item.route).toBe('act')
      expect(details.documents.some(document => document.documentType === 'report')).toBe(true)
      expect(details.history.filter(event => event.action === 'complete_act')).toHaveLength(1)
    } finally {
      await Promise.all([initiator.dispose(), manager.dispose(), executor.dispose()])
    }
  })
}


test('выбор маршрута защищён от гонки, завершение акта идемпотентно', async ({ baseURL }) => {
  const initiator = await apiFor(baseURL, 3)
  const manager = await apiFor(baseURL, 1)
  const laboratory = await apiFor(baseURL, 7)
  try {
    const created = await expectOk(await initiator.post('/api/v1/requests', { data: {
      productName: `Гонка маршрутов ${Date.now()}`, manufacturer: 'Завод', supplier: 'Поставщик', sampleQuantity: 1, testMethod: 'Программа',
    } }))
    const id = created.id
    const results = await Promise.all([manager, laboratory].map(actor => actor.post(`/api/v1/requests/${id}/route`, {
      data: { route: 'act', lockVersion: 1 },
    })))
    expect(results.map(response => response.status()).sort()).toEqual([200, 409])
    await expectOk(await manager.post(`/api/v1/requests/${id}/executor`, { data: { executorId: 2, lockVersion: 2 } }))
    await expectOk(await manager.post(`/api/v1/requests/${id}/start`, { data: { lockVersion: 3 } }))
    await expectOk(await manager.post(`/api/v1/requests/${id}/report`, {
      multipart: { file: { name: 'act.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
    }))
    const options = { headers: { 'Idempotency-Key': crypto.randomUUID() }, data: { lockVersion: 5 } }
    const completed = await expectOk(await manager.post(`/api/v1/requests/${id}/complete-act`, options))
    const replay = await manager.post(`/api/v1/requests/${id}/complete-act`, options)
    expect(await expectOk(replay)).toEqual(completed)
    expect(replay.headers()['idempotency-replayed']).toBe('true')
    expect((await laboratory.post(`/api/v1/requests/${id}/complete-act`, { data: { lockVersion: 5 } })).status()).toBe(409)
    const details = await expectOk(await initiator.get(`/api/v1/requests/${id}`))
    expect(details.history.filter(event => event.action === 'complete_act')).toHaveLength(1)
    expect(details.history.filter(event => event.action === 'choose_route')).toHaveLength(1)
  } finally {
    await Promise.all([initiator.dispose(), manager.dispose(), laboratory.dispose()])
  }
})
