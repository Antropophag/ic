<script setup>
import { reactive, ref } from 'vue'
import { authApi, hasCsrfToken, setCsrfToken } from '../api'
import AetherRibbonMesh from './AetherRibbonMesh.vue'

const emit = defineEmits(['authenticated'])
const loginForm = reactive({ login: '', password: '' })
const loginLoading = ref(false)
const loginError = ref('')

async function login() {
  if (loginLoading.value) return
  if (!loginForm.login || !loginForm.password) {
    loginError.value = 'Введите логин и пароль.'
    return
  }
  loginLoading.value = true
  loginError.value = ''
  try {
    if (!hasCsrfToken()) {
      const bootstrap = await authApi.me()
      setCsrfToken(bootstrap.csrfToken)
    }
    const result = await authApi.login(loginForm.login, loginForm.password)
    setCsrfToken(result.csrfToken)
    loginForm.password = ''
    emit('authenticated', result.user)
  } catch (error) {
    loginError.value = error.status === 401
      ? 'Неверный логин или пароль.'
      : error.status === 403
        ? 'Учётная запись отключена в портале. Обратитесь к администратору.'
        : 'Не удалось войти. Попробуйте ещё раз.'
  } finally {
    loginLoading.value = false
  }
}
</script>

<template>
  <div class="auth-screen">
    <AetherRibbonMesh />
    <form class="auth-card" @submit.prevent="login">
      <svg class="brand-mark" width="48" height="48" viewBox="0 0 40 40" fill="none" aria-hidden="true">
        <rect x="2" y="2" width="36" height="36" rx="10" fill="currentColor" />
        <path d="M12 25a8 8 0 1 1 16 0" stroke="#fff" stroke-width="2" stroke-linecap="round" />
        <path d="M12 25h2M26 25h2M20 15v2" stroke="#fff" stroke-width="1.6" stroke-linecap="round" />
        <path d="M20 25l5-6.5" stroke="#fff" stroke-width="2" stroke-linecap="round" />
        <circle cx="20" cy="25" r="1.6" fill="#fff" />
      </svg>
      <p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p>
      <h1>Вход в портал</h1>
      <label>Логин<input v-model="loginForm.login" autocomplete="username" placeholder="ii.ivanov" required :disabled="loginLoading" /></label>
      <label>Пароль<input v-model="loginForm.password" type="password" autocomplete="current-password" placeholder="Пароль от учётной записи" required :disabled="loginLoading" /></label>
      <p v-if="loginError" class="form-error">{{ loginError }}</p>
      <button class="primary" :disabled="loginLoading">{{ loginLoading ? 'Вход…' : 'Войти' }}</button>
    </form>
  </div>
</template>
