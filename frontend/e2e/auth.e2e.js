import { expect, test } from '@playwright/test'

const login = process.env.TEST_AD_LOGIN
const password = process.env.TEST_AD_PASSWORD

test('изменяющий запрос без CSRF-токена отклоняется в test-окружении', async ({ request }) => {
  const response = await request.post('/api/v1/requests', {
    headers: { 'X-Test-User-ID': '3' },
    data: { productName: 'CSRF must reject' },
  })

  expect(response.status()).toBe(400)
})

test('настоящий AD принимает корректные учётные данные и возвращает профиль', async ({ request }) => {
  expect(login).toBeTruthy()
  expect(password).toBeTruthy()
  const me = await request.get('/api/v1/auth/me')
  const { csrfToken } = await me.json()
  const response = await request.post('/api/v1/auth/login', {
    headers: { 'X-CSRF-Token': csrfToken },
    data: { login, password },
  })
  expect(response.ok(), await response.text()).toBe(true)
  const body = await response.json()
  expect(body.user.displayName).toBe('Initiator Test')
  expect(body.user.email).toBe('initiator@ic.test')
})

test('настоящий AD отклоняет неверный пароль', async ({ request }) => {
  const me = await request.get('/api/v1/auth/me')
  const { csrfToken } = await me.json()
  const response = await request.post('/api/v1/auth/login', {
    headers: { 'X-CSRF-Token': csrfToken },
    data: { login, password: 'wrong-password' },
  })
  expect(response.status()).toBe(401)
})
