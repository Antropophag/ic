export function authErrorMessage(error) {
  if (error.status === 401) return 'Неверный логин или пароль.'
  if (error.status === 403) return 'Учётная запись отключена в портале. Обратитесь к администратору.'
  if (error.status === 503) {
    return 'Сервер авторизации недоступен. Обратитесь в техническую поддержку.'
  }
  return 'Не удалось войти. Попробуйте ещё раз.'
}
