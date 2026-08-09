import fs from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import SwaggerParser from '@apidevtools/swagger-parser'

const frontendRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const repositoryRoot = path.resolve(frontendRoot, '..')
const specificationPath = path.join(repositoryRoot, 'openapi', 'openapi.yaml')
const uiPath = path.join(repositoryRoot, 'openapi', 'swagger-ui', 'index.html')

const source = await fs.readFile(specificationPath, 'utf8')
if (/\$ref\s*:\s*['"]?https?:\/\//i.test(source)) {
  throw new Error('Remote OpenAPI references are forbidden; validation must remain offline')
}

const api = await SwaggerParser.validate(specificationPath, { resolve: { http: false } })
const ui = await fs.readFile(uiPath, 'utf8')

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

assert(api.openapi === '3.0.3', 'The supported OpenAPI version must remain explicit')
assert(api.info?.title && api.info?.version, 'OpenAPI info.title and info.version are required')
assert(api.servers?.some(({ url }) => url === '/'), 'The same-origin server base path is required')
assert(api.paths?.['/health/live']?.get, 'The public health operation is missing')
const login = api.paths?.['/api/v1/auth/login']?.post
const directoryUnavailableMessage = 'Сервер авторизации недоступен. Обратитесь в техническую поддержку.'
assert(login, 'The login operation is missing')
assert(login.responses?.['200'] && login.responses?.['503'],
  'The login operation must document success and directory unavailability')
const directoryUnavailableSchema = api.components?.schemas?.DirectoryUnavailableError
const directoryUnavailableResponseSchema = login.responses['503'].content?.['application/json']?.schema
assert(
  directoryUnavailableResponseSchema?.$ref === '#/components/schemas/DirectoryUnavailableError'
    || directoryUnavailableResponseSchema === directoryUnavailableSchema,
  'The login directory-unavailable response must use DirectoryUnavailableError',
)
const directoryUnavailableMessageEnum = directoryUnavailableSchema?.properties?.message?.enum
assert(
  Array.isArray(directoryUnavailableMessageEnum)
    && directoryUnavailableMessageEnum.length === 1
    && directoryUnavailableMessageEnum[0] === directoryUnavailableMessage,
  'The login directory-unavailable schema must constrain one user-facing message',
)
assert(
  login.responses['503'].content?.['application/json']?.example?.message === directoryUnavailableMessage,
  'The login directory-unavailable response must match the user-facing message',
)
assert(api.paths?.['/api/v1/requests']?.get, 'The protected request list operation is missing')

const requestList = api.paths['/api/v1/requests'].get
assert(requestList.security?.some((requirement) => requirement.cookieSession),
  'The request list must require the session cookie')
assert(requestList.responses?.['401'] && requestList.responses?.['422'],
  'The request list must document authentication and validation errors')

const mutation = api.paths?.['/api/v1/requests/{id}/comments']?.post
assert(mutation, 'The representative mutating operation is missing')
assert(
  mutation.parameters?.some(({ name, in: location, required }) =>
    name === 'Idempotency-Key' && location === 'header' && required === true),
  'The mutating operation must require Idempotency-Key',
)
assert(
  mutation.security?.some((requirement) => requirement.cookieSession && requirement.csrfToken),
  'The mutating operation must require both session cookie and CSRF token',
)
assert(mutation.responses?.['201']?.headers?.['Idempotency-Replayed'],
  'The mutating success response must describe replay state')
for (const status of ['400', '401', '404', '409', '415', '422']) {
  assert(mutation.responses?.[status], `The mutating operation must document HTTP ${status}`)
}
assert(api.components?.securitySchemes?.cookieSession?.in === 'cookie', 'Session auth must be cookie-based')
assert(api.components?.securitySchemes?.csrfToken?.name === 'X-CSRF-Token', 'The actual CSRF header is required')
assert(api.components?.schemas?.UserProfile?.nullable === true, 'The auth/me user profile must allow null')
assert(ui.includes("url: '/api/openapi.yaml'"), 'Swagger UI must use the local specification')
assert(ui.includes('supportedSubmitMethods: []'), 'Swagger Try it out must remain disabled')
assert(!/https?:\/\//i.test(ui), 'Swagger UI must not load assets or contracts from the network')
assert(!/(DB_PASSWORD|LDAP_HOST|COOKIE_VALIDATION_KEY|BREAK_GLASS_PASSWORD_HASH)/.test(source),
  'OpenAPI must not contain sensitive configuration names')
assert(!/(X-Dev-User-ID|X-Test-User-ID|\/api\/v1\/dev\/)/.test(source),
  'OpenAPI must not expose environment-only identity mechanisms')

process.stdout.write(`OpenAPI ${api.info.version} is valid; references and infrastructure contracts passed\n`)
