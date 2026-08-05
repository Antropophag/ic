import { test, expect } from '@playwright/test'

test('production frontend serves the local OpenAPI contract and Swagger UI', async ({ request }) => {
  const specification = await request.get('/api/openapi.yaml')
  expect(specification.status()).toBe(200)
  expect(specification.headers()['content-type']).toContain('application/yaml')
  const source = await specification.text()
  expect(source).toContain('openapi: 3.0.3')
  expect(source).toContain('/api/v1/requests/{id}/comments:')
  expect(source).not.toMatch(/DB_PASSWORD|LDAP_HOST|COOKIE_VALIDATION_KEY|BREAK_GLASS_PASSWORD_HASH/)

  const docs = await request.get('/api/docs/')
  expect(docs.status()).toBe(200)
  const html = await docs.text()
  expect(html).toContain("url: '/api/openapi.yaml'")
  expect(html).toContain('supportedSubmitMethods: []')
  expect(html).not.toMatch(/<script[^>]+https?:|<link[^>]+https?:/i)

  expect((await request.get('/api/docs/swagger-ui.css')).status()).toBe(200)
  expect((await request.get('/api/docs/swagger-ui-bundle.js')).status()).toBe(200)
})
