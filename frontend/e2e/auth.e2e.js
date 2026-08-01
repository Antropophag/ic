import { expect, test } from '@playwright/test'

test('настоящий AD принимает корректные учётные данные и возвращает профиль', async ({ request }) => {
  const response = await request.post('/api/v1/auth/login', {
    data: { login: 'initiator', password: 'TestPassword1!' },
  })
  expect(response.ok(), await response.text()).toBe(true)
  const body = await response.json()
  expect(body.user.displayName).toBe('Initiator Test')
  expect(body.user.email).toBe('initiator@ic.test')
})

test('настоящий AD отклоняет неверный пароль', async ({ request }) => {
  const response = await request.post('/api/v1/auth/login', {
    data: { login: 'initiator', password: 'wrong-password' },
  })
  expect(response.status()).toBe(401)
})
