import { expect, test } from '@playwright/test'

const mailpit = process.env.MAILPIT_BASE_URL || 'http://localhost:18025'

test('новая заявка доставляет письма через настоящий SMTP без повторной отправки', async ({ request }) => {
  await fetch(`${mailpit}/api/v1/messages`, { method: 'DELETE' })
  const marker = `SMTP-E2E-${Date.now()}`
  const response = await request.post('/api/v1/requests', {
    headers: { 'X-Test-User-ID': '3' },
    data: {
      productName: marker,
      manufacturer: 'E2E',
      supplier: 'E2E',
      sampleQuantity: 1,
      testMethod: 'SMTP contract',
    },
  })
  expect(response.ok(), await response.text()).toBe(true)

  let messages = []
  await expect.poll(async () => {
    const result = await fetch(`${mailpit}/api/v1/messages`).then(value => value.json())
    messages = result.messages || result.items || []
    return messages.length
  }, { timeout: 10_000 }).toBeGreaterThan(0)

  const count = messages.length
  expect(messages.some(message => String(message.Subject || message.subject).includes('Заявка'))).toBe(true)
  await new Promise(resolve => setTimeout(resolve, 1000))
  const stable = await fetch(`${mailpit}/api/v1/messages`).then(value => value.json())
  expect((stable.messages || stable.items || []).length).toBe(count)
})
