<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { authApi, devApi, setCsrfToken } from './api'
import AuthScreen from './components/AuthScreen.vue'
import AppModal from './components/AppModal.vue'
import AdminPanel from './components/AdminPanel.vue'
import RequestDetails from './components/RequestDetails.vue'
import RequestRegistry from './components/RequestRegistry.vue'
import { createLatestRequestGuard } from './latestRequestGuard'
import { requestIdFromLocation, setRequestInUrl } from './requestDeepLink'
import { avatarRoleClass, initialsFor } from './registry'

const authLoading = ref(true)
const authUser = ref(null)
const selectedRequestId = ref(requestIdFromLocation())
const selectedRequestTitle = ref(null)
const showAdmin = ref(false)
const requestWarning = ref('')
const registryRefreshTrigger = ref(0)
const authGuard = createLatestRequestGuard()
const showDemoSeedConfirm = ref(false)
const demoSeedLoading = ref(false)
const demoSeedMessage = ref('')
const isDevelopment = import.meta.env.MODE === 'development'
const ReviewGuide = isDevelopment ? defineAsyncComponent(() => import('../dev/ReviewGuide.vue')) : null
const showReviewGuide = ref(isDevelopment && window.location.pathname.replace(/\/+$/, '') === '/review-guide')

const currentProfile = computed(() => ({
  displayName: authUser.value?.displayName || '',
  position: authUser.value?.position || '',
  department: authUser.value?.department || '',
  roles: authUser.value?.roles || [],
}))
const currentInitials = computed(() => initialsFor(currentProfile.value.displayName))
const isAdministrator = computed(() => (currentProfile.value.roles || []).includes('administrator'))
const accountAvatarClass = computed(() => {
  const roles = new Set(currentProfile.value.roles || [])
  const rolePriority = ['administrator', 'security_officer', 'ic_manager', 'laboratory_manager', 'expert', 'ic_executor']
  const primaryRole = rolePriority.find(role => roles.has(role)) || 'employee'
  return avatarRoleClass(primaryRole)
})

watch(authUser, async user => {
  if (!user) {
    const panel = document.querySelector('.development-tools')
    if (panel) document.body.append(panel)
    return
  }
  await nextTick()
  const slot = document.getElementById('ic-development-tools-slot')
  const panel = document.querySelector('body > .development-tools')
  if (slot && panel) slot.append(panel)
})

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
  selectedRequestTitle.value = item.date ? item : null
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
  showReviewGuide.value = false
  showAdmin.value = false
  closeRequest()
  if (isDevelopment && window.location.pathname !== '/') window.history.pushState({}, '', '/')
}

function openAdmin() {
  showReviewGuide.value = false
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
  showReviewGuide.value = isDevelopment && window.location.pathname.replace(/\/+$/, '') === '/review-guide'
  showAdmin.value = false
  selectedRequestId.value = requestIdFromLocation()
  selectedRequestTitle.value = null
}

function openReviewGuide() {
  if (!isDevelopment || showReviewGuide.value) return
  const guideUrl = new URL(window.location.href)
  guideUrl.pathname = '/review-guide'
  showReviewGuide.value = true
  showAdmin.value = false
  selectedRequestId.value = null
  selectedRequestTitle.value = null
  requestWarning.value = ''
  window.history.pushState({}, '', `${guideUrl.pathname}${guideUrl.search}${guideUrl.hash}`)
}

function leaveReviewGuide() {
  showReviewGuide.value = false
  showAdmin.value = false
  const portalUrl = new URL(window.location.href)
  portalUrl.pathname = '/'
  window.history.pushState({}, '', `${portalUrl.pathname}${portalUrl.search}${portalUrl.hash}`)
  selectedRequestId.value = requestIdFromLocation()
  selectedRequestTitle.value = null
}

function requestDemoSeed() {
  showDemoSeedConfirm.value = true
  demoSeedMessage.value = ''
}

async function seedDemoRequests() {
  if (demoSeedLoading.value) return
  demoSeedLoading.value = true
  demoSeedMessage.value = ''
  try {
    const result = await devApi.seedRequests()
    showDemoSeedConfirm.value = false
    closeRequest({ push: false })
    showAdmin.value = false
    registryRefreshTrigger.value += 1
    demoSeedMessage.value = `Создано демонстрационных заявок: ${result.requests}.`
  } catch {
    demoSeedMessage.value = 'Не удалось создать демонстрационные данные.'
  } finally {
    demoSeedLoading.value = false
  }
}

onMounted(() => {
  window.addEventListener('popstate', handlePopState)
  window.addEventListener('ic:request-demo-seed', requestDemoSeed)
  window.addEventListener('ic:open-review-guide', openReviewGuide)
  window.addEventListener('ic:close-review-guide', leaveReviewGuide)
  bootstrapAuth()
})
onBeforeUnmount(() => {
  window.removeEventListener('popstate', handlePopState)
  window.removeEventListener('ic:request-demo-seed', requestDemoSeed)
  window.removeEventListener('ic:open-review-guide', openReviewGuide)
  window.removeEventListener('ic:close-review-guide', leaveReviewGuide)
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
              <button type="button" class="brand-mark-btn" title="На главную" :disabled="!selectedRequestId && !showAdmin && !showReviewGuide" @click="returnHome">
                <svg class="brand-mark" width="48" height="48" viewBox="0 0 40 40" fill="none" aria-hidden="true"><rect x="2" y="2" width="36" height="36" rx="10" fill="currentColor" /><path d="M12 25a8 8 0 1 1 16 0" stroke="#fff" stroke-width="2" stroke-linecap="round" /><path d="M12 25h2M26 25h2M20 15v2" stroke="#fff" stroke-width="1.6" stroke-linecap="round" /><path d="M20 25l5-6.5" stroke="#fff" stroke-width="2" stroke-linecap="round" /><circle cx="20" cy="25" r="1.6" fill="#fff" /></svg>
              </button>
              <div><p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p><h1>{{ showReviewGuide ? 'Обзор портала' : selectedRequestTitle ? `Заявка №${selectedRequestTitle.id} от ${selectedRequestTitle.date}` : selectedRequestId ? 'Заявка' : 'Заявки на проведение испытаний' }}</h1></div>
            </div>
            <div class="header-account">
              <div class="header-account-actions">
                <button v-if="isAdministrator" type="button" class="secondary" @click="openAdmin">Администрирование</button>
                <button type="button" class="secondary" @click="logout">Выйти</button>
              </div>
              <div id="ic-development-tools-slot" class="development-tools-slot"></div>
              <div class="profile">
                <span class="avatar account-avatar" :class="accountAvatarClass">{{ currentInitials }}</span><span><b>{{ currentProfile.displayName }}</b><small>{{ currentProfile.position }}</small></span>
              </div>
            </div>
          </div>
        </header>
        <ReviewGuide v-if="showReviewGuide && ReviewGuide" />
        <AdminPanel v-else-if="showAdmin" @close="showAdmin = false" @open-request="openAdminRequest" />
        <RequestDetails v-else-if="selectedRequestId" :request-id="selectedRequestId" :current-initials="currentInitials" :initial-warning="requestWarning" @loaded="selectedRequestTitle = $event" @updated="refreshRegistry" @close="closeRequest()" />
        <RequestRegistry
          v-else
          :active="!showAdmin && !selectedRequestId"
          :current-user-id="authUser.id"
          :refresh-trigger="registryRefreshTrigger"
          @select-request="openRequest"
        />
      </main>
      <AppModal :open="showDemoSeedConfirm" title="Создать демонстрационные данные" title-id="demo-seed-title" description-id="demo-seed-description" size="small" alert :busy="demoSeedLoading" @close="showDemoSeedConfirm = false">
        <p id="demo-seed-description">Все существующие заявки, комментарии и файлы будут безвозвратно удалены и заменены демонстрационными данными. Пользователи не изменятся.</p>
        <p v-if="demoSeedMessage" class="form-error" role="alert">{{ demoSeedMessage }}</p>
        <template #footer><button type="button" class="secondary" :disabled="demoSeedLoading" @click="showDemoSeedConfirm = false">Отмена</button><button type="button" class="primary danger" :disabled="demoSeedLoading" @click="seedDemoRequests">{{ demoSeedLoading ? 'Заполнение…' : 'Заполнить данные' }}</button></template>
      </AppModal>
      <p v-if="demoSeedMessage && !showDemoSeedConfirm" class="development-tools-notice" role="status">{{ demoSeedMessage }}</p>
    </template>
  </div>
</template>
