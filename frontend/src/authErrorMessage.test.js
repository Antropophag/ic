import { describe, expect, it } from 'vitest'
import { authErrorMessage } from './authErrorMessage'

describe('authentication error messages', () => {
  it.each([
    [401, 'Неверный логин или пароль.'],
    [403, 'Учётная запись отключена в портале. Обратитесь к администратору.'],
    [503, 'Сервер авторизации недоступен. Подключитесь к рабочей сети или VPN и попробуйте ещё раз.'],
    [500, 'Не удалось войти. Попробуйте ещё раз.'],
  ])('maps HTTP %s to an unambiguous message', (status, expected) => {
    expect(authErrorMessage({ status })).toBe(expected)
  })
})
