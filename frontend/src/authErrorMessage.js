export function authErrorMessage(error) {
  if (error.status === 401) return 'Неверный логин или пароль.'
  if (error.status === 403) return 'Учётная запись отключена в портале. Обратитесь к администратору.'
  if (error.status === 503) {
    return 'Сервер авторизации недоступен. Подключитесь к рабочей сети или VPN и попробуйте ещё раз.'
  }
  return 'Не удалось войти. Попробуйте ещё раз.'
}
