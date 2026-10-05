import { expect, test } from '@playwright/test'

for (const width of [390, 1440]) {
  test(`обзор объясняет актуальные маршруты и возвращает в реестр при ширине ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.route('**/api/**', route => {
      const path = new URL(route.request().url()).pathname
      const user = { id: 1, displayName: 'Демо пользователь', roles: ['employee'] }
      const json = path.endsWith('/auth/me') ? { user, csrfToken: 'test-token' }
        : path.endsWith('/dev/users') ? { items: [user] }
          : { items: [], categories: [], counts: { active: 0, all: 0, mine: 0 }, total: 0, page: 1, pageSize: 10, pageCount: 0 }
      return route.fulfill({ json })
    })
    await page.goto('/review-guide')
    const guide = page.locator('.guide-page')
    await expect(guide).toBeVisible()
    await expect(guide).toContainText('Любое решение СБ автоматически завершает заявку')
    await expect(guide).toContainText('В заявке от 1 до 10 объектов')
    await expect(guide).toContainText('Обязательны причина и номер или ссылка на обращение в ИТ')
    await expect(guide).not.toContainText('СБ согласует или возвращает документы')
    await expect.poll(() => guide.locator('img').evaluateAll(images => images.every(image => image.complete && image.naturalWidth > 0))).toBe(true)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.getByRole('button', { name: 'Вернуться в портал', exact: true }).click()
    await expect(guide).toHaveCount(0)
    await expect(page).toHaveURL(/\/$/)
    await expect(page.locator('.registry')).toBeVisible()
  })
}
