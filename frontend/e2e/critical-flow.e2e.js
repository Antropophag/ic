import { expect, request as playwrightRequest, test } from '@playwright/test'

async function apiFor(baseURL, userId) {
  const bootstrap = await playwrightRequest.newContext({
    baseURL,
    extraHTTPHeaders: { 'X-Test-User-ID': String(userId) },
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
      'X-Test-User-ID': String(userId),
      'X-CSRF-Token': csrfToken,
    },
  })
  return {
    get: (...args) => context.get(...args),
    post: (path, options = {}) => context.post(path, {
      ...options,
      headers: { ...options.headers, 'Idempotency-Key': crypto.randomUUID() },
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
    await route.continue({ headers: { ...route.request().headers(), 'X-Test-User-ID': String(userId) } })
  })
}

test('заявка проходит критический путь до согласования СБ', async ({ page, baseURL }) => {
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

  const assigned = await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, {
    data: { executorId: 2, lockVersion: 1 },
  }))
  expect(assigned).toMatchObject({ executorId: 2, lockVersion: 2 })

  const started = await expectOk(await manager.post(`/api/v1/requests/${requestId}/start`, {
    data: { lockVersion: 2 },
  }))
  expect(started).toMatchObject({ status: 'in_progress', lockVersion: 3 })
  await expectOk(await executor.post(`/api/v1/requests/${requestId}/report`, {
    multipart: { file: { name: 'e2e-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
  }))
  await expectOk(await expert.post(`/api/v1/requests/${requestId}/expert/claim`, {
    data: { lockVersion: 4 },
  }))
  await expectOk(await expert.post(`/api/v1/requests/${requestId}/opinion`, {
    data: { body: 'Образец соответствует требованиям критического E2E-сценария.', lockVersion: 5 },
  }))

  await page.route('**/api/**', async route => {
    await route.continue({ headers: { ...route.request().headers(), 'X-Test-User-ID': '5' } })
  })
  await page.goto('/')
  await page.getByRole('row').filter({ hasText: marker }).click()
  await expect(page.locator('.object-status-row').getByText('Контроль СБ', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Лента заявки', exact: true })).toBeVisible()
  await expect(page.getByText('опубликовал(а) экспертное заключение', { exact: false })).toBeVisible()
  const securityMarkIcon = page.locator('.side-column .security-mark-icon')
  await expect(securityMarkIcon).toBeVisible()
  await expect(securityMarkIcon).toHaveCSS('display', 'inline-flex')
  await expect(securityMarkIcon).toHaveCSS('align-items', 'center')
  await expect(securityMarkIcon).toHaveCSS('margin-bottom', '0px')
  await page.getByRole('button', { name: 'Согласовать и завершить' }).click()
  await page.getByRole('button', { name: 'Согласовать', exact: true }).click()
  await expect(page.locator('.object-status-row').getByText('Заявка выполнена', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Согласовать и завершить' })).toHaveCount(0)

  await Promise.all([initiator.dispose(), manager.dispose(), executor.dispose(), expert.dispose()])
})

test('комментарий, оставленный при создании заявки, появляется в её ленте', async ({ page }) => {
  const marker = `E2E-comment-${Date.now()}`
  const comment = 'Срочно, испытания нужны до конца недели.'

  await useTestIdentity(page, 3)
  await page.goto('/')
  await page.getByRole('button', { name: '＋ Новая заявка' }).click()
  await page.getByPlaceholder('Введите наименование продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Тестовый производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Тестовый поставщик')
  await page.getByPlaceholder('Опишите метод или программу испытаний').fill('Комментарий при создании — E2E')
  await page.getByPlaceholder('Дополнительная информация').fill(comment)
  await page.getByRole('button', { name: 'Создать заявку' }).click()

  await expect(page.getByRole('heading', { name: /^Заявка №\d+ от \d{1,2}\.\d{1,2}\.\d{4}$/ })).toBeVisible()
  await expect(page.getByText(comment)).toBeVisible()
})

test('черновик новой заявки восстанавливается и удаляется после создания', async ({ page }) => {
  const marker = `E2E-draft-${Date.now()}`
  await useTestIdentity(page, 3)
  await page.goto('/')
  await page.getByRole('button', { name: '＋ Новая заявка' }).click()
  await page.getByPlaceholder('Введите наименование продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Черновой производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Черновой поставщик')
  await page.getByPlaceholder('Опишите метод или программу испытаний').fill('Проверка восстановления')

  await expect.poll(() => page.evaluate(key => localStorage.getItem(key), 'ic.application-create-draft.v1.3'))
    .toContain(marker)
  await page.reload()
  await page.getByRole('button', { name: '＋ Новая заявка' }).click()
  await expect(page.getByRole('status')).toHaveText('Черновик заявки восстановлен.')
  await expect(page.getByPlaceholder('Введите наименование продукции')).toHaveValue(marker)
  await expect(page.getByPlaceholder('Наименование производителя')).toHaveValue('Черновой производитель')
  await expect(page.getByPlaceholder('Наименование поставщика')).toHaveValue('Черновой поставщик')
  await page.getByRole('button', { name: 'Создать заявку' }).click()

  await expect(page.getByRole('heading', { name: /^Заявка №\d+ от \d{1,2}\.\d{1,2}\.\d{4}$/ })).toBeVisible()
  await expect.poll(() => page.evaluate(key => localStorage.getItem(key), 'ic.application-create-draft.v1.3'))
    .toBeNull()
  await page.getByTitle('На главную').click()
  await page.getByRole('button', { name: '＋ Новая заявка' }).click()
  await expect(page.getByPlaceholder('Введите наименование продукции')).toHaveValue('')
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
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, {
    data: { executorId: 2, lockVersion: 1 },
  }))
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/start`, {
    data: { lockVersion: 2 },
  }))
  await expectOk(await executor.post(`/api/v1/requests/${requestId}/report`, {
    multipart: { file: { name: 'e2e-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } },
  }))
  const commentText = 'Отчёт направлен на согласование'
  await expectOk(await executor.post(`/api/v1/requests/${requestId}/comments`, {
    data: { body: commentText },
  }))

  await page.route('**/api/**', async route => {
    await route.continue({ headers: { ...route.request().headers(), 'X-Test-User-ID': '2' } })
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
  await expect(page.getByText(commentText)).toBeVisible()
  await page.getByRole('button', { name: 'Закрыть' }).click()
  await expect(page.getByText(commentText)).toHaveCount(0)

  await Promise.all([initiator.dispose(), manager.dispose(), executor.dispose()])
})

test('кнопка «назад» браузера возвращает из карточки заявки в реестр', async ({ page }) => {
  const marker = `E2E-back-${Date.now()}`
  const errors = []
  page.on('pageerror', error => errors.push(error.message))

  await useTestIdentity(page, 3)
  await page.goto('/')
  await page.getByRole('button', { name: '＋ Новая заявка' }).click()
  await page.getByPlaceholder('Введите наименование продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Тестовый производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Тестовый поставщик')
  await page.getByPlaceholder('Опишите метод или программу испытаний').fill('Кнопка назад браузера — E2E')
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
  await expect(page.getByRole('heading', { name: 'Пользователи и роли' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Тестовый сотрудник', exact: true })).toBeVisible()

  await page.getByRole('button', { name: '← К реестру заявок' }).click()
  await expect(page.getByRole('heading', { name: 'Пользователи и роли' })).toHaveCount(0)
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
    const departmentFact = page.locator('.object-band .fact').filter({ hasText: 'Подразделение' })
    await expect(departmentFact.locator('b')).toHaveText('Тестовое подразделение')
    await page.getByRole('button', { name: 'Изменить', exact: true }).click()
    await page.getByLabel('Подразделение', { exact: true }).fill('Подразделение C')
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
    await expect(departmentFact.locator('b')).toHaveText('Подразделение C')
    await expect(page.getByText('изменил(а) подразделение заявки: Подразделение C', { exact: false })).toBeVisible()

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
    await page.getByRole('button', { name: 'Изменить', exact: true }).click()
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
      await route.continue({ headers: { ...route.request().headers(), 'X-Test-User-ID': '6' } })
    })

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
    await refreshObserved
    await expect(page.getByRole('button', { name: 'Изменить', exact: true })).toHaveCount(0)
    releaseRefresh()

    await expect(page.getByText('Заявка уже изменена. Данные обновлены', { exact: false })).toBeVisible()
    await expect(page.locator('.object-band .fact').filter({ hasText: 'Подразделение' }).locator('b'))
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
  await expectOk(await manager.post(`/api/v1/requests/${created.id}/executor`, {
    data: { executorId: 2, lockVersion: 1 },
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
  await expect(page.locator('.object-title', { hasText: marker })).toBeVisible()
  await page.getByRole('button', { name: 'Администрирование' }).click()
  await page.getByRole('tab', { name: 'Уведомления' }).click()
  await page.getByRole('spinbutton', { name: 'Заявка' }).fill(String(created.id))
  await page.getByLabel('Статус').selectOption({ label: statusLabel })
  await page.getByRole('button', { name: 'Применить' }).click()
  await expect(page.locator('.admin-log-table .badge', { hasText: statusLabel }).first()).toBeVisible()
  await expect(page.getByText('SECRET BODY')).toHaveCount(0)
  await page.getByRole('button', { name: new RegExp(`Заявка №`) }).first().press('Enter')
  await expect(page.locator('.object-title', { hasText: marker })).toBeVisible()
  } finally {
    await Promise.allSettled(contexts.map(context => context.dispose()))
  }
})
