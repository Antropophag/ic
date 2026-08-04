<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { authApi, setCsrfToken } from './api'
import AuthScreen from './components/AuthScreen.vue'
import AdminPanel from './components/AdminPanel.vue'
import RequestDetails from './components/RequestDetails.vue'
import RequestRegistry from './components/RequestRegistry.vue'
import { createLatestRequestGuard } from './latestRequestGuard'
import { requestIdFromLocation, setRequestInUrl } from './requestDeepLink'
import { initialsFor } from './registry'

const authLoading = ref(true)
const authUser = ref(null)
const selectedRequestId = ref(requestIdFromLocation())
const selectedRequestTitle = ref(null)
const showAdmin = ref(false)
const requestWarning = ref('')
const registryRefreshTrigger = ref(0)
const authGuard = createLatestRequestGuard()

const currentProfile = computed(() => ({
  displayName: authUser.value?.displayName || '',
  position: authUser.value?.position || '',
  department: authUser.value?.department || '',
  roles: authUser.value?.roles || [],
}))
const currentInitials = computed(() => initialsFor(currentProfile.value.displayName))
const isAdministrator = computed(() => (currentProfile.value.roles || []).includes('administrator'))

async function bootstrapAuth() {
  const token = authGuard.begin(true)
  authLoading.value = true
  try {
    const result = await authApi.me()
    if (!authGuard.isCurrent(token, true)) return
    setCsrfToken(result.csrfToken)
    authUser.value = result.user
  } catch {
    if (!authGuard.isCurrent(token, true)) return
    authUser.value = null
  } finally {
    if (authGuard.isCurrent(token, true)) authLoading.value = false
  }
}

function openRequest(item, warning = '') {
  selectedRequestId.value = item.backendId
  selectedRequestTitle.value = item
  requestWarning.value = warning
  showAdmin.value = false
  setRequestInUrl(item.backendId, { push: true })
}

function closeRequest({ push = true } = {}) {
  if (selectedRequestId.value) registryRefreshTrigger.value += 1
  selectedRequestId.value = null
  selectedRequestTitle.value = null
  requestWarning.value = ''
  setRequestInUrl(null, { push })
}

function returnHome() {
  showAdmin.value = false
  closeRequest()
}

function openAdmin() {
  showAdmin.value = true
  closeRequest({ push: false })
}

function openAdminRequest(requestId) {
  const id = Number(requestId)
  if (!Number.isInteger(id) || id <= 0) return
  selectedRequestId.value = id
  selectedRequestTitle.value = null
  requestWarning.value = ''
  showAdmin.value = false
  setRequestInUrl(selectedRequestId.value, { push: true })
}

function refreshRegistry() {
  registryRefreshTrigger.value += 1
}

async function logout() {
  const token = authGuard.begin(true)
  let csrfToken = ''
  try {
    const result = await authApi.logout()
    csrfToken = result.csrfToken
  } catch {
    // Logout still clears local state when the current request fails.
  }
  if (!authGuard.isCurrent(token, true)) return
  setCsrfToken(csrfToken)
  authUser.value = null
  closeRequest({ push: false })
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
  authGuard.invalidate()
})
</script>

<template>
  <div class="shell">
    <div v-if="authLoading" class="auth-loading">Загрузка…</div>
    <AuthScreen v-else-if="!authUser" @authenticated="authUser = $event" />
    <template v-else>
      <main>
        <header class="topbar">
          <div class="topbar-inner">
            <div class="brand-block">
              <button type="button" class="brand-mark-btn" title="На главную" :disabled="!selectedRequestId && !showAdmin" @click="returnHome">
                <svg class="brand-mark" width="48" height="48" viewBox="0 0 40 40" fill="none" aria-hidden="true"><rect x="2" y="2" width="36" height="36" rx="10" fill="currentColor" /><path d="M12 25a8 8 0 1 1 16 0" stroke="#fff" stroke-width="2" stroke-linecap="round" /><path d="M12 25h2M26 25h2M20 15v2" stroke="#fff" stroke-width="1.6" stroke-linecap="round" /><path d="M20 25l5-6.5" stroke="#fff" stroke-width="2" stroke-linecap="round" /><circle cx="20" cy="25" r="1.6" fill="#fff" /></svg>
              </button>
              <div><p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p><h1>{{ selectedRequestTitle ? `Заявка №${selectedRequestTitle.id} от ${selectedRequestTitle.date}` : selectedRequestId ? 'Заявка' : 'Заявки на проведение испытаний' }}</h1></div>
            </div>
            <div class="profile">
              <span class="avatar">{{ currentInitials }}</span><span><b>{{ currentProfile.displayName }}</b><small>{{ currentProfile.position }}</small></span>
              <button v-if="isAdministrator" type="button" class="secondary" @click="openAdmin">Администрирование</button>
              <button type="button" class="secondary" @click="logout">Выйти</button>
            </div>
          </div>
        </header>
        <AdminPanel v-if="showAdmin" @close="showAdmin = false" @open-request="openAdminRequest" />
        <RequestDetails v-else-if="selectedRequestId" :request-id="selectedRequestId" :current-initials="currentInitials" :initial-warning="requestWarning" @loaded="selectedRequestTitle = $event" @updated="refreshRegistry" @close="closeRequest()" />
        <RequestRegistry
          :active="!showAdmin && !selectedRequestId"
          :refresh-trigger="registryRefreshTrigger"
          @select-request="openRequest"
        />
      </main>
    </template>
  </div>
</template>
