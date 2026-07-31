<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { authApi, setCsrfToken } from './api'
import AuthScreen from './components/AuthScreen.vue'
import AdminPanel from './components/AdminPanel.vue'
import RequestDetails from './components/RequestDetails.vue'
import RequestRegistry from './components/RequestRegistry.vue'
import { getDevUserId, reconcileDevUserId, setDevUserId } from './devUsers'
import { createLatestRequestGuard } from './latestRequestGuard'
import { requestIdFromLocation, setRequestInUrl } from './requestDeepLink'
import { initialsFor } from './registry'

const authLoading = ref(true)
const authDevMode = ref(true)
const authUser = ref(null)
const devUsers = ref([])
const devUserId = ref(getDevUserId())
const devUsersError = ref('')
const devUsersLoading = ref(false)
const selectedRequestId = ref(requestIdFromLocation())
const selectedRequestTitle = ref(null)
const showAdmin = ref(false)
const registry = ref(null)
const devUsersGuard = createLatestRequestGuard()

const currentProfile = computed(() => {
  if (authDevMode.value) {
    return devUsers.value.find(user => user.id === devUserId.value) ?? devUsers.value[0]
      ?? { displayName: '', position: '', department: '', roles: [] }
  }
  return {
    displayName: authUser.value?.displayName || '',
    position: authUser.value?.position || '',
    department: authUser.value?.department || '',
    roles: authUser.value?.roles || [],
  }
})
const currentInitials = computed(() => initialsFor(currentProfile.value.displayName))
const isAdministrator = computed(() => (currentProfile.value.roles || []).includes('administrator'))

async function loadDevUsers() {
  if (devUsersLoading.value) return false
  devUsersLoading.value = true
  const token = devUsersGuard.begin(true)
  try {
    const result = await authApi.devUsers()
    if (!devUsersGuard.isCurrent(token, true)) return false
    const items = Array.isArray(result.items) ? result.items : []
    if (!items.length) {
      devUsersError.value = 'Список dev-пользователей пуст. Выполните ./yii dev/seed на backend.'
      return false
    }
    devUsers.value = items
    devUserId.value = reconcileDevUserId(items)
    devUsersError.value = ''
    return true
  } catch {
    if (devUsersGuard.isCurrent(token, true)) devUsersError.value = 'Не удалось загрузить список dev-пользователей.'
    return false
  } finally {
    if (devUsersGuard.isCurrent(token, true)) devUsersLoading.value = false
  }
}

async function bootstrapAuth() {
  authLoading.value = true
  try {
    const result = await authApi.me()
    setCsrfToken(result.csrfToken)
    authDevMode.value = Boolean(result.devMode)
    authUser.value = result.user
    if (authDevMode.value) await loadDevUsers()
  } catch {
    authDevMode.value = false
    authUser.value = null
  } finally {
    authLoading.value = false
  }
}

function openRequest(item) {
  selectedRequestId.value = item.backendId
  selectedRequestTitle.value = item
  showAdmin.value = false
  setRequestInUrl(item.backendId, { push: true })
}

function closeRequest({ push = true } = {}) {
  selectedRequestId.value = null
  selectedRequestTitle.value = null
  setRequestInUrl(null, { push })
}

function switchDevUser(rawId) {
  const id = Number(rawId)
  setDevUserId(id)
  devUserId.value = id
  closeRequest()
}

async function logout() {
  try {
    const result = await authApi.logout()
    setCsrfToken(result.csrfToken)
  } catch {
    setCsrfToken('')
  } finally {
    authUser.value = null
    closeRequest({ push: false })
  }
}

function handlePopState() {
  showAdmin.value = false
  selectedRequestId.value = requestIdFromLocation()
  selectedRequestTitle.value = null
}

onMounted(() => {
  window.addEventListener('popstate', handlePopState)
  bootstrapAuth()
})
onBeforeUnmount(() => {
  window.removeEventListener('popstate', handlePopState)
  devUsersGuard.invalidate()
})
</script>

<template>
  <div class="shell">
    <div v-if="authLoading" class="auth-loading">Загрузка…</div>
    <div v-else-if="devUsersError" class="auth-screen">
      <div class="auth-card">
        <p class="form-error">{{ devUsersError }}</p>
        <button type="button" class="primary" :disabled="devUsersLoading" @click="loadDevUsers">
          {{ devUsersLoading ? 'Повтор…' : 'Повторить' }}
        </button>
      </div>
    </div>
    <AuthScreen v-else-if="!authDevMode && !authUser" @authenticated="authUser = $event" />
    <template v-else>
      <main>
        <header class="topbar">
          <div class="topbar-inner">
            <div class="brand-block">
              <button type="button" class="brand-mark-btn" title="На главную" :disabled="!selectedRequestId" @click="closeRequest()">
                <svg class="brand-mark" width="48" height="48" viewBox="0 0 40 40" fill="none" aria-hidden="true"><rect x="2" y="2" width="36" height="36" rx="10" fill="currentColor" /><path d="M12 25a8 8 0 1 1 16 0" stroke="#fff" stroke-width="2" stroke-linecap="round" /><path d="M12 25h2M26 25h2M20 15v2" stroke="#fff" stroke-width="1.6" stroke-linecap="round" /><path d="M20 25l5-6.5" stroke="#fff" stroke-width="2" stroke-linecap="round" /><circle cx="20" cy="25" r="1.6" fill="#fff" /></svg>
              </button>
              <div><p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p><h1>{{ selectedRequestTitle ? `Заявка №${selectedRequestTitle.id} от ${selectedRequestTitle.date}` : selectedRequestId ? 'Заявка' : 'Заявки на проведение испытаний' }}</h1></div>
            </div>
            <div class="profile">
              <select v-if="authDevMode" class="dev-user-switch" title="Dev-переключатель пользователя (только APP_ENV=dev)" :value="devUserId" @change="switchDevUser($event.target.value)"><option v-for="user in devUsers" :key="user.id" :value="user.id">{{ user.displayName }} — {{ user.position }}</option></select>
              <button v-if="authDevMode" type="button" class="secondary demo-seed-button" :disabled="registry?.demoSeedLoading" @click="registry?.seedDemoRequests()">{{ registry?.demoSeedLoading ? 'Заполнение…' : 'Заполнить демо' }}</button>
              <span v-if="authDevMode && registry?.demoSeedMessage" class="demo-seed-message" role="status">{{ registry.demoSeedMessage }}</span>
              <span class="avatar">{{ currentInitials }}</span><span><b>{{ currentProfile.displayName }}</b><small>{{ currentProfile.position }}</small></span>
              <button v-if="isAdministrator" type="button" class="secondary" @click="showAdmin = true; closeRequest({ push: false })">Администрирование</button>
              <button v-if="!authDevMode" type="button" class="secondary" @click="logout">Выйти</button>
            </div>
          </div>
        </header>
        <AdminPanel v-if="showAdmin" @close="showAdmin = false" />
        <RequestDetails v-else-if="selectedRequestId" :request-id="selectedRequestId" :current-initials="currentInitials" @loaded="selectedRequestTitle = $event" @close="closeRequest()" />
        <RequestRegistry
          ref="registry"
          :active="!showAdmin && !selectedRequestId"
          :dev-user-id="devUserId"
          @reset="showAdmin = false; closeRequest({ push: false })"
          @select-request="openRequest"
        />
      </main>
    </template>
  </div>
</template>
