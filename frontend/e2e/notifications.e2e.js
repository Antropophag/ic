import { expect, test } from '@playwright/test'

const mailpit = process.env.MAILPIT_BASE_URL || 'http://localhost:18025'

async function findMessages(marker) {
  const result = await fetch(`${mailpit}/api/v1/messages`).then(value => value.json())
  const messages = result.messages || result.items || []
  const detailed = await Promise.all(messages.map(message =>
    fetch(`${mailpit}/api/v1/message/${message.ID || message.id}`).then(value => value.json()),
  ))

  return detailed
    .filter(message => `${message.Text || ''}${message.HTML || ''}`.includes(marker))
    .map(message => message.ID || message.IDs?.[0] || message.id)
    .sort()
}

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

  let matchingIds = []
  let stablePolls = 0
  let previousCount = 0
  await expect.poll(async () => {
    matchingIds = await findMessages(marker)
    stablePolls = matchingIds.length > 0 && matchingIds.length === previousCount
      ? stablePolls + 1
      : 0
    previousCount = matchingIds.length
    return stablePolls
  }, { intervals: [500], timeout: 10_000 }).toBeGreaterThanOrEqual(3)

  await new Promise(resolve => setTimeout(resolve, 1000))
  expect(await findMessages(marker)).toEqual(matchingIds)
})
