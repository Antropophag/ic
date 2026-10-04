import { expect, test } from '@playwright/test'

test('закрепление начинается с вкладок реестра, верхняя панель прокручивается', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.route('**/api/**', route => route.continue({
    headers: { ...route.request().headers(), 'X-Test-User-ID': '1' },
  }))
  await page.goto('/')
  const tabs = page.locator('.registry-head')
  await expect(tabs).toBeVisible()
  const initial = await tabs.boundingBox()
  expect(initial.y).toBeGreaterThan(80)
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
  await expect.poll(async () => (await page.locator('.topbar').boundingBox()).y).toBeLessThan(0)
  await expect.poll(async () => Math.abs((await tabs.boundingBox()).y)).toBeLessThan(3)
  // Empty registries have no pagination; the filter contract does not require fixtures.
  if (await page.locator('.pagination').count()) {
    const footer = await page.locator('.pagination').boundingBox()
    expect(footer.y + footer.height).toBeLessThanOrEqual(1001)
  }
  const scroll = page.locator('.table-wrap')
  await scroll.evaluate(element => { element.scrollTop = 200 })
  await expect.poll(async () => Math.abs((await tabs.boundingBox()).y)).toBeLessThan(3)
})

test('на узком экране все очереди внимания не скрывают реестр', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.route('**/api/**', route => route.continue({
    headers: { ...route.request().headers(), 'X-Test-User-ID': '1' },
  }))
  await page.route('**/api/v1/requests/dashboard', route => route.fulfill({ json: {
    categories: [
      ['assign_executor', 'Назначить исполнителя'],
      ['start_or_resume_work', 'Начать или возобновить работы'],
      ['upload_report', 'Загрузить отчёт'],
      ['claim_expert', 'Взять заявку на экспертизу'],
      ['publish_opinion', 'Опубликовать заключение'],
      ['security_decision', 'Принять решение СБ'],
    ].map(([id, title]) => ({ id, title, description: 'Требуется действие по заявке', count: 1 })),
  } }))
  await page.goto('/')
  await expect(page.locator('.attention-card')).toHaveCount(6)
  const table = page.getByRole('region', { name: 'Реестр заявок с закреплённой шапкой' })
  await table.scrollIntoViewIfNeeded()
  await expect.poll(async () => (await table.boundingBox()).height).toBeGreaterThan(120)
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
})

test('фильтр направлений применяет черновой выбор, отменяется по Escape и сбрасывается', async ({ page }) => {
  await page.route('**/api/**', route => route.continue({
    headers: { ...route.request().headers(), 'X-Test-User-ID': '1' },
  }))
  await page.goto('/')
  const trigger = page.getByRole('button', { name: 'Фильтр по направлению испытаний', exact: true })
  const dialog = page.getByRole('dialog', { name: 'Направления испытаний', exact: true })
  await trigger.click()
  await expect(dialog).toBeVisible()
  await dialog.getByRole('checkbox', { name: 'Механические испытания', exact: true }).check()
  await dialog.getByRole('checkbox', { name: 'Резерв 2', exact: true }).check()
  await expect(trigger).toHaveAttribute('data-filter-active', 'false')

  const filtered = page.waitForResponse(response => {
    const url = new URL(response.url())
    return url.pathname === '/api/v1/requests' && url.searchParams.get('colors') === 'blue,green'
  })
  await dialog.getByRole('button', { name: 'Применить', exact: true }).click()
  expect((await filtered).status()).toBe(200)
  await expect(dialog).not.toBeVisible()
  await expect(trigger).toHaveAttribute('data-filter-active', 'true')
  await expect(trigger).toBeFocused()

  await trigger.click()
  await dialog.getByRole('checkbox', { name: 'Резерв 1', exact: true }).check()
  await page.keyboard.press('Escape')
  await expect(dialog).not.toBeVisible()
  await expect(trigger).toBeFocused()
  await trigger.click()
  await expect(dialog.getByRole('checkbox', { name: 'Резерв 1', exact: true })).not.toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'Механические испытания', exact: true })).toBeChecked()

  await page.setViewportSize({ width: 800, height: 900 })
  await expect.poll(async () => {
    const box = await dialog.boundingBox()
    return box && box.x >= 0 && box.y >= 0 && box.x + box.width <= 801 && box.y + box.height <= 901
  }).toBe(true)
  const cleared = page.waitForResponse(response => {
    const url = new URL(response.url())
    return url.pathname === '/api/v1/requests' && !url.searchParams.has('colors')
  })
  await dialog.getByRole('button', { name: 'Сбросить', exact: true }).click()
  expect((await cleared).status()).toBe(200)
  await expect(trigger).toHaveAttribute('data-filter-active', 'false')
})
