<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { adminApi } from '../api'
import { createLatestRequestGuard } from '../latestRequestGuard'

const emit = defineEmits(['close'])
const users = ref([])
const roles = ref([])
const loading = ref(false)
const error = ref('')
const newUserAdLogin = ref('')
const newUserRoleId = ref('')
const createUserLoading = ref(false)
const createUserError = ref('')
const roleChoiceByUser = reactive({})
const roleActionError = ref('')
const requestGuard = createLatestRequestGuard()
const createUserGuard = createLatestRequestGuard()
const roleActionTokens = new Map()
const pendingRoleActions = reactive(new Set())
let mounted = true

async function load() {
  loading.value = true
  error.value = ''
  const token = requestGuard.begin(true)
  try {
    const [usersResult, rolesResult] = await Promise.all([adminApi.users(), adminApi.roles()])
    if (!requestGuard.isCurrent(token, true)) return
    users.value = Array.isArray(usersResult.items) ? usersResult.items : []
    roles.value = Array.isArray(rolesResult.items) ? rolesResult.items : []
  } catch {
    if (requestGuard.isCurrent(token, true)) error.value = 'Не удалось загрузить список пользователей.'
  } finally {
    if (requestGuard.isCurrent(token, true)) loading.value = false
  }
}

async function createUser() {
  if (createUserLoading.value) return
  if (!newUserAdLogin.value || !newUserRoleId.value) {
    createUserError.value = 'Укажите логин AD и роль.'
    return
  }
  createUserLoading.value = true
  createUserError.value = ''
  const token = createUserGuard.begin(true)
  try {
    const user = await adminApi.createUser(newUserAdLogin.value)
    if (!createUserGuard.isCurrent(token, true)) return
    let createdUser = user
    try {
      const result = await adminApi.assignRole(user.id, Number(newUserRoleId.value))
      if (!createUserGuard.isCurrent(token, true)) return
      createdUser = { ...user, roles: result.items }
    } catch {
      if (!createUserGuard.isCurrent(token, true)) return
      createUserError.value = 'Пользователь создан, но не удалось сразу назначить роль — назначьте её в списке ниже.'
    }
    users.value = [...users.value, createdUser].sort((a, b) => (a.displayName || '').localeCompare(b.displayName || '', 'ru'))
    newUserAdLogin.value = ''
    newUserRoleId.value = ''
  } catch (caught) {
    if (!createUserGuard.isCurrent(token, true)) return
    createUserError.value = caught.status === 409
      ? 'Пользователь с таким логином AD уже существует.'
      : caught.status === 422
        ? 'Логин AD может содержать только латинские буквы, цифры, точку, дефис и подчёркивание.'
        : 'Не удалось создать пользователя.'
  } finally {
    if (createUserGuard.isCurrent(token, true)) createUserLoading.value = false
  }
}

function updateRoles(userId, nextRoles) {
  users.value = users.value.map(user => user.id === userId ? { ...user, roles: nextRoles } : user)
}

async function assignRole(userId) {
  const roleId = Number(roleChoiceByUser[userId])
  if (!roleId) return
  const key = `assign:${userId}:${roleId}`
  if (pendingRoleActions.has(key)) return
  const token = Symbol(key)
  roleActionTokens.set(userId, token)
  pendingRoleActions.add(key)
  roleActionError.value = ''
  try {
    const result = await adminApi.assignRole(userId, roleId)
    if (!mounted || roleActionTokens.get(userId) !== token) return
    updateRoles(userId, result.items)
    roleChoiceByUser[userId] = ''
  } catch {
    if (mounted && roleActionTokens.get(userId) === token) roleActionError.value = 'Не удалось назначить роль.'
  } finally {
    if (mounted) pendingRoleActions.delete(key)
  }
}

async function revokeRole(userId, roleId) {
  const key = `revoke:${userId}:${roleId}`
  if (pendingRoleActions.has(key)) return
  const token = Symbol(key)
  roleActionTokens.set(userId, token)
  pendingRoleActions.add(key)
  roleActionError.value = ''
  try {
    const result = await adminApi.revokeRole(userId, roleId)
    if (!mounted || roleActionTokens.get(userId) !== token) return
    updateRoles(userId, result.items)
  } catch {
    if (mounted && roleActionTokens.get(userId) === token) roleActionError.value = 'Не удалось отозвать роль.'
  } finally {
    if (mounted) pendingRoleActions.delete(key)
  }
}

onMounted(load)
onBeforeUnmount(() => {
  mounted = false
  requestGuard.invalidate()
  createUserGuard.invalidate()
  roleActionTokens.clear()
})
</script>

<template>
  <section class="page admin-page">
    <div class="card">
      <div class="section-title"><h3>Пользователи и роли</h3><button type="button" class="secondary" @click="emit('close')">← К реестру заявок</button></div>
      <p v-if="error" class="detail-state error">{{ error }}</p>
      <p v-if="loading" class="detail-state">Загрузка…</p>
      <form v-else class="admin-create-user" @submit.prevent="createUser">
        <label>Логин AD<input v-model="newUserAdLogin" placeholder="ivanov" :disabled="createUserLoading" /></label>
        <label>Роль<select v-model="newUserRoleId" :disabled="createUserLoading"><option value="">Выберите роль…</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
        <button class="primary" :disabled="createUserLoading">{{ createUserLoading ? 'Добавление…' : 'Добавить заранее' }}</button>
      </form>
      <p v-if="createUserError" class="action-error">{{ createUserError }}</p>
      <p class="hint">Заведённый заранее профиль сразу получит выбранную роль (и базовую роль «Сотрудник») и найдётся по этому же логину при первом реальном входе через LDAP — отображаемое имя будет обновлено данными из AD.</p>
      <p v-if="roleActionError" class="action-error">{{ roleActionError }}</p>
      <div v-if="!loading" class="table-wrap">
        <table><thead><tr><th>ФИО</th><th>Логин AD</th><th>Email</th><th>Активен</th><th>Роли</th></tr></thead><tbody>
          <tr v-for="user in users" :key="user.id"><td><b>{{ user.displayName }}</b></td><td>{{ user.adLogin }}</td><td>{{ user.email || '—' }}</td><td>{{ user.isActive ? 'да' : 'нет' }}</td><td><span v-for="role in user.roles" :key="role.id" class="role-chip">{{ role.name }}<button type="button" title="Отозвать роль" :aria-label="`Отозвать роль ${role.name} у ${user.displayName || user.adLogin}`" :disabled="pendingRoleActions.has(`revoke:${user.id}:${role.id}`)" @click="revokeRole(user.id, role.id)">×</button></span><span class="role-assign"><select v-model="roleChoiceByUser[user.id]"><option value="">Добавить роль…</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><button type="button" class="secondary" :aria-label="`Назначить выбранную роль пользователю ${user.displayName || user.adLogin}`" :disabled="pendingRoleActions.has(`assign:${user.id}:${Number(roleChoiceByUser[user.id])}`)" @click="assignRole(user.id)">+</button></span></td></tr>
        </tbody></table>
      </div>
    </div>
  </section>
</template>
