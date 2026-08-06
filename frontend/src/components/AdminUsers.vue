<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { adminApi } from '../api'
import { createLatestRequestGuard } from '../latestRequestGuard'
import { avatarRoleClass, initialsFor } from '../registry'
import AppIcon from './AppIcon.vue'

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
const pendingRoleUsers = reactive(new Set())
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

function userAvatarClass(user) {
  const roleCodes = new Set((user.roles || []).map(role => role.code))
  const priority = ['administrator', 'security_officer', 'ic_manager', 'laboratory_manager', 'expert', 'ic_executor']
  return avatarRoleClass(priority.find(role => roleCodes.has(role)) || 'employee')
}

async function assignRole(userId) {
  const roleId = Number(roleChoiceByUser[userId])
  if (!roleId) return
  const key = `assign:${userId}:${roleId}`
  if (pendingRoleUsers.has(userId)) return
  const token = Symbol(key)
  roleActionTokens.set(userId, token)
  pendingRoleUsers.add(userId)
  roleActionError.value = ''
  try {
    const result = await adminApi.assignRole(userId, roleId)
    if (!mounted || roleActionTokens.get(userId) !== token) return
    updateRoles(userId, result.items)
    roleChoiceByUser[userId] = ''
  } catch {
    if (mounted && roleActionTokens.get(userId) === token) roleActionError.value = 'Не удалось назначить роль.'
  } finally {
    if (mounted && roleActionTokens.get(userId) === token) pendingRoleUsers.delete(userId)
  }
}

async function revokeRole(userId, roleId) {
  const key = `revoke:${userId}:${roleId}`
  if (pendingRoleUsers.has(userId)) return
  const token = Symbol(key)
  roleActionTokens.set(userId, token)
  pendingRoleUsers.add(userId)
  roleActionError.value = ''
  try {
    const result = await adminApi.revokeRole(userId, roleId)
    if (!mounted || roleActionTokens.get(userId) !== token) return
    updateRoles(userId, result.items)
  } catch {
    if (mounted && roleActionTokens.get(userId) === token) roleActionError.value = 'Не удалось отозвать роль.'
  } finally {
    if (mounted && roleActionTokens.get(userId) === token) pendingRoleUsers.delete(userId)
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
  <section class="admin-section" aria-labelledby="admin-tab-users">
    <div v-if="error" class="admin-state admin-state--error"><span>{{ error }}</span></div>
    <div v-if="loading" class="admin-state">Загрузка…</div>
    <form v-else class="admin-create-user" aria-label="Добавить пользователя" @submit.prevent="createUser">
      <label>Логин AD<input v-model="newUserAdLogin" placeholder="ii.ivanov" :disabled="createUserLoading" /></label>
      <label>Роль<select v-model="newUserRoleId" :disabled="createUserLoading"><option value="">Выберите роль…</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
      <button class="primary" :disabled="createUserLoading">{{ createUserLoading ? 'Добавление…' : 'Добавить пользователя' }}</button>
    </form>
    <p v-if="createUserError" class="action-error">{{ createUserError }}</p>
    <p class="hint">Профиль получит выбранную и базовую роли. При первом входе через LDAP портал найдёт его по логину и обновит отображаемое имя данными из AD.</p>
    <p v-if="roleActionError" class="action-error">{{ roleActionError }}</p>
    <div v-if="!loading && users.length" class="table-wrap admin-table-wrap">
      <table class="admin-table"><thead><tr><th>Пользователь</th><th>Логин AD</th><th>Электронная почта</th><th>Состояние</th><th>Роли</th></tr></thead><tbody>
        <tr v-for="user in users" :key="user.id"><td><div class="admin-person"><span class="avatar small" :class="userAvatarClass(user)">{{ initialsFor(user.displayName) }}</span><b>{{ user.displayName }}</b></div></td><td>{{ user.adLogin }}</td><td class="admin-email" :title="user.email || ''">{{ user.email || '—' }}</td><td><span class="badge" :class="user.isActive ? 'green' : 'gray'">{{ user.isActive ? 'Активен' : 'Отключён' }}</span></td><td><div class="admin-roles"><span v-for="role in user.roles" :key="role.id" class="role-chip">{{ role.name }}<button type="button" title="Отозвать роль" :aria-label="`Отозвать роль ${role.name} у ${user.displayName || user.adLogin}`" :disabled="pendingRoleUsers.has(user.id)" @click="revokeRole(user.id, role.id)"><AppIcon name="close" :size="12" /></button></span><span class="role-assign"><select v-model="roleChoiceByUser[user.id]" :disabled="pendingRoleUsers.has(user.id)" :aria-label="`Новая роль для ${user.displayName || user.adLogin}`"><option value="">Добавить роль…</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><button type="button" class="secondary" :aria-label="`Назначить выбранную роль пользователю ${user.displayName || user.adLogin}`" :disabled="pendingRoleUsers.has(user.id)" @click="assignRole(user.id)"><AppIcon name="plus" :size="14" /></button></span></div></td></tr>
      </tbody></table>
    </div>
    <div v-else-if="!loading" class="admin-empty"><div class="admin-empty-icon" aria-hidden="true"><AppIcon name="search" :size="20" /></div><h3>Пользователей пока нет</h3><p>Добавьте профиль с помощью формы выше.</p></div>
  </section>
</template>
