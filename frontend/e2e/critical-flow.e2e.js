import { expect, request as playwrightRequest, test } from '@playwright/test'

async function apiFor(baseURL, userId) {
  return playwrightRequest.newContext({
    baseURL,
    extraHTTPHeaders: { 'X-Dev-User-ID': String(userId) },
  })
}

async function expectOk(response) {
  expect(response.ok(), await response.text()).toBe(true)
  return response.json()
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
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/executor`, {
    data: { executorId: 2, lockVersion: 1 },
  }))
  await expectOk(await manager.post(`/api/v1/requests/${requestId}/start`, {
    data: { lockVersion: 2 },
  }))
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
    await route.continue({ headers: { ...route.request().headers(), 'X-Dev-User-ID': '5' } })
  })
  await page.goto('/')
  await page.getByRole('row').filter({ hasText: marker }).click()
  await expect(page.getByText('Контроль СБ', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Согласовать и завершить' }).click()
  await page.getByRole('button', { name: 'Согласовать', exact: true }).click()
  await expect(page.getByText('Заявка выполнена', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Согласовать и завершить' })).toHaveCount(0)

  await Promise.all([initiator.dispose(), manager.dispose(), executor.dispose(), expert.dispose()])
})

test('комментарий, оставленный при создании заявки, появляется в её ленте', async ({ page }) => {
  const marker = `E2E-comment-${Date.now()}`
  const comment = 'Срочно, испытания нужны до конца недели.'

  await page.goto('/')
  await page.selectOption('.dev-user-switch', '3')
  await page.getByRole('button', { name: '＋ Новая заявка' }).click()
  await page.getByPlaceholder('Введите наименование продукции').fill(marker)
  await page.getByPlaceholder('Наименование производителя').fill('Тестовый производитель')
  await page.getByPlaceholder('Наименование поставщика').fill('Тестовый поставщик')
  await page.getByPlaceholder('Опишите метод или программу испытаний').fill('Комментарий при создании — E2E')
  await page.getByPlaceholder('Дополнительная информация').fill(comment)
  await page.getByRole('button', { name: 'Создать заявку' }).click()

  await expect(page.getByRole('heading', { name: /^Заявка №\d+ от \d{2}\.\d{2}\.\d{4}$/ })).toBeVisible()
  await expect(page.getByText(comment)).toBeVisible()
})

test('администратор управляет ролями и возвращается в реестр без ошибок рендера', async ({ page }) => {
  // Регрессия: v-else детального экрана заявки был привязан не к тому
  // v-if и срабатывал, когда открыт экран администрирования (selected
  // оставался null) — рендер падал на обращении к полю несуществующей
  // заявки. Экран администрирования должен открываться и закрываться,
  // не ломая реестр заявок.
  const errors = []
  page.on('pageerror', error => errors.push(error.message))

  await page.goto('/')
  await page.selectOption('.dev-user-switch', '6')
  await page.getByRole('button', { name: 'Администрирование' }).click()
  await expect(page.getByRole('heading', { name: 'Пользователи и роли' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Тестовый сотрудник' })).toBeVisible()

  await page.getByRole('button', { name: '← К реестру заявок' }).click()
  await expect(page.getByRole('heading', { name: 'Пользователи и роли' })).toHaveCount(0)
  await expect(page.getByPlaceholder('Поиск по заявкам')).toBeVisible()

  expect(errors).toEqual([])
})
