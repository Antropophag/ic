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

  return playwrightRequest.newContext({
    baseURL,
    storageState,
    extraHTTPHeaders: {
      'X-Test-User-ID': String(userId),
      'X-CSRF-Token': csrfToken,
    },
  })
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

test('администратор читает журналы действий и уведомлений и открывает связанную заявку', async ({ page, baseURL }) => {
  const marker = `E2E-admin-logs-${Date.now()}`
  const initiator = await apiFor(baseURL, 3)
  const manager = await apiFor(baseURL, 1)
  const admin = await apiFor(baseURL, 6)
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
  await page.getByRole('button', { name: new RegExp(`Заявка №`) }).first().click()
  await expect(page.locator('.object-title', { hasText: marker })).toBeVisible()
  await page.getByRole('button', { name: 'Администрирование' }).click()
  await page.getByRole('tab', { name: 'Уведомления' }).click()
  await page.getByRole('spinbutton', { name: 'Заявка' }).fill(String(created.id))
  await page.getByLabel('Статус').selectOption({ label: statusLabel })
  await page.getByRole('button', { name: 'Применить' }).click()
  await expect(page.locator('.admin-log-table .badge', { hasText: statusLabel }).first()).toBeVisible()
  await expect(page.getByText('SECRET BODY')).toHaveCount(0)
  await page.getByRole('button', { name: new RegExp(`Заявка №`) }).first().click()
  await expect(page.locator('.object-title', { hasText: marker })).toBeVisible()
  await Promise.all([initiator.dispose(), manager.dispose(), admin.dispose()])
})
