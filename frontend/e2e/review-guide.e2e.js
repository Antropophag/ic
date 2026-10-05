import { expect, test } from '@playwright/test'

test.use({ deviceScaleFactor: 3 })

for (const width of [320, 390, 1440, 2560, 3840]) {
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
    const creation = guide.locator('#request-creation')
    await expect(creation).toContainText('единственную позицию удалить нельзя')
    await expect(creation).toContainText('Когда добавлены 10 объектов, кнопка «Добавить объект» недоступна')
    await expect(creation.getByRole('img')).toHaveAttribute('src', '/review-guide-assets/request-create-objects-hires.png')
    const act = guide.locator('[aria-labelledby="act-route-title"]')
    const protocol = guide.locator('[aria-labelledby="protocol-route-title"]')
    await expect(act.locator('ol li')).toHaveCount(4)
    await expect(guide).toContainText('нажимает «Завершить заявку»')
    await expect(protocol.locator('ol li')).toHaveCount(6)
    await page.evaluate(() => document.fonts.ready)
    const actSteps = await act.locator('ol li').evaluateAll(items => items.map(item => { const { x, y, width, height } = item.getBoundingClientRect(); return { x, y, width, height } }))
    const protocolSteps = await protocol.locator('ol li').evaluateAll(items => items.map(item => { const { x, y, width, height } = item.getBoundingClientRect(); return { x, y, width, height } }))
    expect(actSteps[0].x + actSteps[0].width).toBeLessThan(protocolSteps[0].x)
    for (let index = 0; index < actSteps.length; index += 1) {
      expect(Math.abs(actSteps[index].y - protocolSteps[index].y)).toBeLessThan(1)
      expect(Math.abs(actSteps[index].height - protocolSteps[index].height)).toBeLessThan(1)
      expect(Math.abs(actSteps[index].width - protocolSteps[index].width)).toBeLessThan(1)
    }
    expect(actSteps.at(-1).y).toBeLessThan(protocolSteps.at(-1).y)
    for (const step of await guide.locator('.route-step').all()) {
      expect(await step.evaluate(element => {
        const box = element.getBoundingClientRect()
        const content = element.querySelector('div').getBoundingClientRect()
        return content.bottom <= box.bottom && content.right <= box.right
      })).toBe(true)
    }
    await expect(guide).toContainText('Обязательны причина и номер или ссылка на обращение в ИТ')
    await expect(guide).not.toContainText('СБ согласует или возвращает документы')
    await expect.poll(() => guide.locator('img').evaluateAll(images => images.every(image => image.complete && image.naturalWidth > 0))).toBe(true)
    const images = await guide.locator('img').evaluateAll(images => images.map(image => ({
      pixels: image.naturalWidth,
      requiredPixels: image.getBoundingClientRect().width * devicePixelRatio,
    })))
    expect(images).toHaveLength(13)
    for (const image of images) {
      expect(image.pixels).toBeGreaterThanOrEqual(4800)
      expect(image.pixels).toBeGreaterThanOrEqual(Math.floor(image.requiredPixels))
    }
    const crops = await guide.locator('img').evaluateAll(images => Object.fromEntries(images.map(image => [
      image.src.split('/').pop(), image.naturalHeight / image.naturalWidth,
    ])))
    expect(crops['registry-list-crop-hires.png']).toBeLessThan(0.5)
    expect(crops['request-overview-crop-hires.png']).toBeCloseTo(1796 / 2694, 2)
    expect(crops['notifications-context-crop-hires.png']).toBeCloseTo(900 / 1440, 2)
    expect(crops['help-open-crop-hires.png']).toBeCloseTo(1000 / 1440, 2)
    const originals = await guide.locator('.screenshot-link').evaluateAll(links => links.every(link => link.href === link.querySelector('img').src && link.target === '_blank'))
    expect(originals).toBe(true)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.getByRole('button', { name: 'Вернуться в портал', exact: true }).click()
    await expect(guide).toHaveCount(0)
    await expect(page).toHaveURL(/\/$/)
    await expect(page.locator('.registry')).toBeVisible()
  })
}
