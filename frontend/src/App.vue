<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { adminApi, authApi, hasCsrfToken, requestApi, setCsrfToken } from './api'
import { DEV_USERS, getDevUserId, setDevUserId } from './devUsers'
import { createConfirmDialog } from './confirmDialog'
import { createLatestRequestGuard } from './latestRequestGuard'
import { ACTIVE_STATUSES, REGISTRY_PAGE_SIZE, REQUEST_COLORS, buildFeed, canSubmitComment, commentFromApi, documentFromApi, filterRequests, fromApi, historyFromApi, paginate, withoutStaleActions } from './registry'

const activeTab = ref('active')
const query = ref('')
const statusFilter = ref('')
const sortDirection = ref('desc')
const currentPage = ref(1)
const pageSize = REGISTRY_PAGE_SIZE
const selected = ref(null)
const showCreate = ref(false)
const createError = ref('')
const registryError = ref('')
const createLoading = ref(false)
const draftFiles = ref([])
const actionError = ref('')
const actionLoading = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const commentDraft = ref('')
const commentLoading = ref(false)
const commentError = ref('')
const olderCommentsLoading = ref(false)
const documentLoading = ref(false)
const documentError = ref('')
const reportLoading = ref(false)
const reportError = ref('')
const executors = ref([])
const executorChoice = ref('')
const experts = ref([])
const expertChoice = ref('')
const opinionDraft = ref('')
const opinionLoading = ref(false)
const opinionError = ref('')
const securityReason = ref('')
const securityLoading = ref(false)
const securityError = ref('')
const colorLoading = ref(false)
const colorError = ref('')
const rejectLoading = ref(false)
const rejectError = ref('')
const withdrawLoading = ref(false)
const withdrawError = ref('')
const claimLoading = ref(false)
const claimError = ref('')
const reassignLoading = ref(false)
const reassignError = ref('')
const deleteReportLoading = ref(false)
const deleteReportError = ref('')
const detailRequestGuard = createLatestRequestGuard()
const commentRequestGuard = createLatestRequestGuard()
const commentsPageRequestGuard = createLatestRequestGuard()
const documentRequestGuard = createLatestRequestGuard()
const reportRequestGuard = createLatestRequestGuard()
const opinionRequestGuard = createLatestRequestGuard()
const securityRequestGuard = createLatestRequestGuard()
const registryRequestGuard = createLatestRequestGuard()
const colorRequestGuard = createLatestRequestGuard()
const rejectRequestGuard = createLatestRequestGuard()
const withdrawRequestGuard = createLatestRequestGuard()
const claimRequestGuard = createLatestRequestGuard()
const reassignRequestGuard = createLatestRequestGuard()
const deleteReportRequestGuard = createLatestRequestGuard()
const adminRequestGuard = createLatestRequestGuard()
const confirmDialog = createConfirmDialog()
const draft = reactive({
  productName: '', manufacturer: '', supplier: '', sampleQuantity: 1, testMethod: '', comment: '',
})
const devUserId = ref(getDevUserId())
// Пессимистичный старт (dev-режим), пока /auth/me не ответит — не мигаем
// формой логина в единственном реально dev-развёртывании (локальная разработка/CI).
const authDevMode = ref(true)
const authLoading = ref(true)
const authUser = ref(null)
const loginForm = reactive({ login: '', password: '' })
const loginLoading = ref(false)
const loginError = ref('')

const currentProfile = computed(() => {
  if (authDevMode.value) {
    return DEV_USERS.find(user => user.id === devUserId.value) ?? DEV_USERS[0]
  }
  return {
    displayName: authUser.value?.displayName || '',
    position: authUser.value?.position || '',
    department: authUser.value?.department || '',
    roles: authUser.value?.roles || [],
  }
})
const currentInitials = computed(() => (currentProfile.value.displayName
  .split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase()) || '?')
const isAdministrator = computed(() => (currentProfile.value.roles || []).includes('administrator'))
const feed = computed(() => buildFeed(selected.value?.history || [], selected.value?.comments || []))
const hasHeroAction = computed(() => Boolean(selected.value && (
  selected.value.canAssignExecutor || selected.value.canStart || selected.value.canUploadReport
  || selected.value.canClaimExpert || selected.value.canReassignExpert || selected.value.canPublishOpinion
  || selected.value.canSecurityDecide || selected.value.canReject || selected.value.canWithdraw
  || selected.value.canDeleteReport
)))

function switchDevUser(rawId) {
  const id = Number(rawId)
  setDevUserId(id)
  devUserId.value = id
  selected.value = null
  loadRequests()
}

async function bootstrapAuth() {
  authLoading.value = true
  try {
    const result = await authApi.me()
    setCsrfToken(result.csrfToken)
    authDevMode.value = Boolean(result.devMode)
    authUser.value = result.user
    if (authDevMode.value || authUser.value) {
      await loadRequests()
    }
  } catch {
    // /auth/me недоступен (сеть/сервер) — остаёмся на форме логина, если
    // не dev; повторная попытка доступна через кнопку логина.
    authDevMode.value = false
    authUser.value = null
  } finally {
    authLoading.value = false
  }
}

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
      // Начальный /auth/me мог не выполниться (сеть/сервер) — без токена
      // сам логин будет отклонён CSRF-проверкой вне dev, поэтому сначала
      // добираем токен перед попыткой входа.
      const bootstrap = await authApi.me()
      setCsrfToken(bootstrap.csrfToken)
    }
    const result = await authApi.login(loginForm.login, loginForm.password)
    setCsrfToken(result.csrfToken)
    authUser.value = result.user
    loginForm.password = ''
    await loadRequests()
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

async function logout() {
  try {
    const result = await authApi.logout()
    setCsrfToken(result.csrfToken)
  } catch {
    setCsrfToken('')
  } finally {
    authUser.value = null
    selected.value = null
    requests.value = []
  }
}

const showAdmin = ref(false)
const adminUsers = ref([])
const adminRoles = ref([])
const adminLoading = ref(false)
const adminError = ref('')
const newUserAdLogin = ref('')
const newUserDisplayName = ref('')
const createUserLoading = ref(false)
const createUserError = ref('')
const roleChoiceByUser = reactive({})
const roleActionError = ref('')

async function openAdmin() {
  selected.value = null
  showAdmin.value = true
  adminError.value = ''
  adminLoading.value = true
  const requestToken = adminRequestGuard.begin(true)
  try {
    const [usersResult, rolesResult] = await Promise.all([adminApi.users(), adminApi.roles()])
    if (!adminRequestGuard.isCurrent(requestToken, true)) return
    adminUsers.value = usersResult.items
    adminRoles.value = rolesResult.items
  } catch {
    if (adminRequestGuard.isCurrent(requestToken, true)) {
      adminError.value = 'Не удалось загрузить список пользователей.'
    }
  } finally {
    if (adminRequestGuard.isCurrent(requestToken, true)) {
      adminLoading.value = false
    }
  }
}

function closeAdmin() {
  adminRequestGuard.invalidate()
  showAdmin.value = false
}

async function createAdminUser() {
  if (createUserLoading.value) return
  if (!newUserAdLogin.value || !newUserDisplayName.value) {
    createUserError.value = 'Заполните логин AD и отображаемое имя.'
    return
  }
  createUserLoading.value = true
  createUserError.value = ''
  try {
    const user = await adminApi.createUser(newUserAdLogin.value, newUserDisplayName.value)
    adminUsers.value = [...adminUsers.value, user].sort((a, b) => a.displayName.localeCompare(b.displayName, 'ru'))
    newUserAdLogin.value = ''
    newUserDisplayName.value = ''
  } catch (error) {
    createUserError.value = error.status === 409
      ? 'Пользователь с таким логином AD уже существует.'
      : error.status === 422
        ? 'Логин AD может содержать только латинские буквы, цифры, точку, дефис и подчёркивание.'
        : 'Не удалось создать пользователя.'
  } finally {
    createUserLoading.value = false
  }
}

async function assignAdminRole(userId) {
  const roleId = Number(roleChoiceByUser[userId])
  if (!roleId) return
  roleActionError.value = ''
  try {
    const result = await adminApi.assignRole(userId, roleId)
    updateAdminUserRoles(userId, result.items)
    roleChoiceByUser[userId] = ''
  } catch {
    roleActionError.value = 'Не удалось назначить роль.'
  }
}

async function revokeAdminRole(userId, roleId) {
  roleActionError.value = ''
  try {
    const result = await adminApi.revokeRole(userId, roleId)
    updateAdminUserRoles(userId, result.items)
  } catch {
    roleActionError.value = 'Не удалось отозвать роль.'
  }
}

function updateAdminUserRoles(userId, roles) {
  adminUsers.value = adminUsers.value.map(user => (user.id === userId ? { ...user, roles } : user))
}

const requests = ref([
  { id: '000146', date: '27.07.2026', initiator: 'Максим Умнов', department: 'Бюро приводной техники', product: 'Лебёдка Furder VT40K', supplier: 'ООО «Вектор Технологий»', executor: 'С. И. Кашин', status: 'Заявка зарегистрирована', tone: 'blue', color: 'white', securityMark: '—' },
  { id: '000145', date: '27.07.2026', initiator: 'Виктор Медведев', department: 'Отдел производственных закупок', product: 'IP-видеокамера DS-2CD2543G2-IS', supplier: 'ООО «Видеотехнология»', executor: 'С. В. Наумов', status: 'Заявка в работе', tone: 'cyan', color: 'white', securityMark: '—' },
  { id: '000144', date: '24.07.2026', initiator: 'Андрей Соколов', department: 'Отдел главного конструктора', product: 'Ограничитель скорости ОС-2', supplier: 'АО «Лифткомплект»', executor: 'С. В. Прикуль', status: 'Работы приостановлены', tone: 'orange', color: 'white', securityMark: '—' },
  { id: '000143', date: '22.07.2026', initiator: 'Елена Орлова', department: 'Служба закупок', product: 'Частотный преобразователь 15 кВт', supplier: 'ООО «Электропривод»', executor: 'С. Д. Шапошников', status: 'Подготовка заключения', tone: 'violet', color: 'white', securityMark: '—' },
  { id: '000142', date: '21.07.2026', initiator: 'Павел Зимин', department: 'Управление качества', product: 'Буфер полиуретановый БП-100', supplier: 'ООО «Полимер»', executor: 'В. Я. Галкин', status: 'Контроль СБ', tone: 'yellow', color: 'white', securityMark: '✕' },
  { id: '000141', date: '18.07.2026', initiator: 'Ирина Белова', department: 'Служба закупок', product: 'Датчик положения кабины', supplier: 'ООО «Сенсорика»', executor: 'В. В. Козлов', status: 'Заявка выполнена', tone: 'green', color: 'white', securityMark: '✓' },
])

const tabs = computed(() => [
  { id: 'active', label: 'Активные заявки', count: requests.value.filter(item => ACTIVE_STATUSES.includes(item.status)).length },
  { id: 'all', label: 'Все заявки', count: requests.value.length },
  { id: 'mine', label: 'Мои заявки', count: requests.value.filter(item => item.initiator === currentProfile.value.displayName).length },
])
const statuses = computed(() => [...new Set(requests.value.map(item => item.status))])
const filtered = computed(() => filterRequests(requests.value, {
  tab: activeTab.value,
  query: query.value,
  status: statusFilter.value,
  currentUser: currentProfile.value.displayName,
  sortDirection: sortDirection.value,
}))
const paged = computed(() => paginate(filtered.value, currentPage.value))
const pageNumbers = computed(() => Array.from({ length: paged.value.pageCount }, (_, i) => i + 1))

watch([activeTab, query, statusFilter, sortDirection], () => {
  currentPage.value = 1
})

function toggleSort() {
  sortDirection.value = sortDirection.value === 'desc' ? 'asc' : 'desc'
}

async function loadRequestDetails(item) {
  const requestToken = detailRequestGuard.begin(item.backendId)
  selected.value = item
  detailError.value = ''
  detailLoading.value = true
  executorChoice.value = item.executorId || ''
  expertChoice.value = ''
  if (item.canAssignExecutor && !executors.value.length) loadExecutors()
  if (item.canReassignExpert && !experts.value.length) loadExperts()
  try {
    const result = await requestApi.get(item.backendId)
    if (!detailRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    selected.value = {
      ...fromApi(result.item),
      history: result.history.map(historyFromApi),
      comments: result.comments.map(commentFromApi),
      commentsPage: result.commentsPage,
      documents: result.documents.map(documentFromApi),
    }
    executorChoice.value = selected.value.executorId || ''
    expertChoice.value = ''
    if (selected.value.canAssignExecutor && !executors.value.length) loadExecutors()
    if (selected.value.canReassignExpert && !experts.value.length) loadExperts()
  } catch (error) {
    if (!detailRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    detailError.value = error.status === 404
      ? 'Заявка не найдена или недоступна.'
      : 'Не удалось загрузить актуальную карточку заявки.'
  } finally {
    if (detailRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      detailLoading.value = false
    }
  }
}

async function openRequest(item) {
  commentRequestGuard.invalidate()
  commentsPageRequestGuard.invalidate()
  documentRequestGuard.invalidate()
  reportRequestGuard.invalidate()
  opinionRequestGuard.invalidate()
  securityRequestGuard.invalidate()
  colorRequestGuard.invalidate()
  rejectRequestGuard.invalidate()
  withdrawRequestGuard.invalidate()
  claimRequestGuard.invalidate()
  reassignRequestGuard.invalidate()
  deleteReportRequestGuard.invalidate()
  commentLoading.value = false
  commentError.value = ''
  commentDraft.value = ''
  documentLoading.value = false
  documentError.value = ''
  reportLoading.value = false
  reportError.value = ''
  opinionLoading.value = false
  opinionError.value = ''
  opinionDraft.value = ''
  securityLoading.value = false
  securityError.value = ''
  securityReason.value = ''
  colorLoading.value = false
  colorError.value = ''
  rejectLoading.value = false
  rejectError.value = ''
  withdrawLoading.value = false
  withdrawError.value = ''
  claimLoading.value = false
  claimError.value = ''
  reassignLoading.value = false
  reassignError.value = ''
  deleteReportLoading.value = false
  deleteReportError.value = ''
  actionError.value = ''
  await loadRequestDetails(item)
}

function closeRequest() {
  detailRequestGuard.invalidate()
  commentRequestGuard.invalidate()
  commentsPageRequestGuard.invalidate()
  documentRequestGuard.invalidate()
  reportRequestGuard.invalidate()
  opinionRequestGuard.invalidate()
  securityRequestGuard.invalidate()
  colorRequestGuard.invalidate()
  rejectRequestGuard.invalidate()
  withdrawRequestGuard.invalidate()
  claimRequestGuard.invalidate()
  reassignRequestGuard.invalidate()
  deleteReportRequestGuard.invalidate()
  selected.value = null
  showAdmin.value = false
  detailLoading.value = false
  detailError.value = ''
  commentLoading.value = false
  commentError.value = ''
  documentLoading.value = false
  documentError.value = ''
  reportLoading.value = false
  reportError.value = ''
  opinionLoading.value = false
  opinionError.value = ''
  securityLoading.value = false
  securityError.value = ''
  securityReason.value = ''
  colorLoading.value = false
  colorError.value = ''
  rejectLoading.value = false
  rejectError.value = ''
  withdrawLoading.value = false
  withdrawError.value = ''
  claimLoading.value = false
  claimError.value = ''
  reassignLoading.value = false
  reassignError.value = ''
  deleteReportLoading.value = false
  deleteReportError.value = ''
}

async function uploadReport(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const requestId = selected.value.backendId
  const requestToken = reportRequestGuard.begin(requestId)
  reportLoading.value = true
  reportError.value = ''
  try {
    await requestApi.uploadReport(requestId, file)
    if (!reportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    await loadRequestDetails(selected.value)
    await loadRequests()
  } catch (error) {
    if (!reportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    reportError.value = error.status === 413
      ? 'Файл слишком большой. Максимальный размер отчёта — 10 МБ.'
      : error.status === 422
        ? 'Отчёт должен быть PDF-файлом размером до 10 МБ.'
        : error.status === 403
          ? 'Загрузить отчёт может назначенный исполнитель или руководитель.'
          : 'Не удалось загрузить отчёт.'
  } finally {
    if (reportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      reportLoading.value = false
    }
  }
}

async function uploadDocument(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const requestId = selected.value.backendId
  const requestToken = documentRequestGuard.begin(requestId)
  documentLoading.value = true
  documentError.value = ''
  try {
    await requestApi.uploadDocument(requestId, file)
    if (!documentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    await loadRequestDetails(selected.value)
  } catch (error) {
    if (!documentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    documentError.value = error.status === 413
      ? 'Файл слишком большой. Максимальный размер — 10 МБ.'
      : error.status === 422
        ? 'Разрешены PDF, PNG, JPG, DOCX и XLSX размером до 10 МБ.'
        : error.status === 409
          ? 'На текущем этапе загрузка документов запрещена.'
          : 'Не удалось загрузить документ.'
  } finally {
    if (documentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      documentLoading.value = false
    }
  }
}

async function downloadDocument(document) {
  documentError.value = ''
  try {
    const blob = await requestApi.downloadDocument(document.versionId)
    const url = URL.createObjectURL(blob)
    const link = window.document.createElement('a')
    link.href = url
    link.download = document.originalName
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    documentError.value = 'Не удалось скачать документ.'
  }
}

async function addComment() {
  if (!commentDraft.value.trim()) {
    commentError.value = 'Введите текст комментария.'
    return
  }
  commentLoading.value = true
  commentError.value = ''
  const requestId = selected.value.backendId
  const requestToken = commentRequestGuard.begin(requestId)
  try {
    const result = await requestApi.addComment(requestId, commentDraft.value)
    if (!commentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    detailRequestGuard.invalidate()
    detailLoading.value = false
    selected.value = {
      ...selected.value,
      comments: [...(selected.value.comments || []), commentFromApi(result)],
    }
    commentDraft.value = ''
  } catch (error) {
    if (!commentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 422) {
      commentError.value = 'Комментарий пуст или превышает допустимый размер.'
    } else if (error.status === 409) {
      await loadRequestDetails(selected.value)
      commentError.value = 'На текущем этапе новые комментарии запрещены.'
    } else {
      commentError.value = 'Не удалось добавить комментарий.'
    }
  } finally {
    if (commentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      commentLoading.value = false
    }
  }
}

async function loadOlderComments() {
  const requestId = selected.value.backendId
  const beforeId = selected.value.commentsPage?.nextBeforeId
  if (!beforeId) return
  const requestToken = commentsPageRequestGuard.begin(requestId)
  olderCommentsLoading.value = true
  commentError.value = ''
  try {
    const result = await requestApi.comments(requestId, beforeId)
    if (!commentsPageRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    selected.value = {
      ...selected.value,
      comments: [...result.items.map(commentFromApi), ...(selected.value.comments || [])],
      commentsPage: { hasMore: result.hasMore, nextBeforeId: result.nextBeforeId },
    }
  } catch {
    if (commentsPageRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      commentError.value = 'Не удалось загрузить предыдущие комментарии.'
    }
  } finally {
    if (commentsPageRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      olderCommentsLoading.value = false
    }
  }
}

async function loadRequests(rethrow = false) {
  const requestToken = registryRequestGuard.begin(devUserId.value)
  try {
    const result = await requestApi.list()
    if (!registryRequestGuard.isCurrent(requestToken, devUserId.value)) return
    requests.value = result.items.map(fromApi)
  } catch (error) {
    if (!registryRequestGuard.isCurrent(requestToken, devUserId.value)) return
    // Макет остаётся доступным отдельно от backend; ошибка будет видна в Network.
    if (rethrow) throw error
  }
}

async function createRequest() {
  if (createLoading.value) return
  createError.value = ''
  registryError.value = ''
  createLoading.value = true
  let created
  try {
    created = await requestApi.create(draft)
  } catch (error) {
    createError.value = error.status === 422
      ? 'Проверьте обязательные поля формы.'
      : error.status === 403
        ? 'Ваш профиль не может подавать заявки. Обратитесь к администратору.'
        : 'Не удалось создать заявку. Повторите попытку.'
    createLoading.value = false
    return
  }

  const failedFiles = []
  for (const file of draftFiles.value) {
    try {
      await requestApi.uploadDocument(created.id, file)
    } catch (error) {
      const reason = error.status === 413
        ? 'файл слишком большой, максимум 10 МБ'
        : error.status === 422
          ? 'недопустимый формат или размер'
          : 'ошибка загрузки'
      failedFiles.push(`${file.name} (${reason})`)
    }
  }

  const comment = draft.comment.trim()
  let commentFailed = false
  if (comment) {
    try {
      await requestApi.addComment(created.id, comment)
    } catch {
      commentFailed = true
    }
  }

  Object.assign(draft, { productName: '', manufacturer: '', supplier: '', sampleQuantity: 1, testMethod: '', comment: '' })
  draftFiles.value = []
  const commentMessage = commentFailed ? ' Комментарий не удалось сохранить.' : ''
  try {
    await loadRequests(true)
    const createdItem = requests.value.find(item => item.backendId === created.id)
    if (createdItem) {
      await openRequest(createdItem)
      if (failedFiles.length) {
        documentError.value = `Заявка создана, но не удалось загрузить: ${failedFiles.join(', ')}.`
      }
      if (commentFailed) {
        commentError.value = 'Заявка создана, но комментарий не удалось сохранить.'
      }
    } else {
      registryError.value = `Заявка создана, но пока не появилась в реестре. Не создавайте её повторно; обновите страницу.${commentMessage}`
    }
  } catch {
    const fileMessage = failedFiles.length ? ` Не загружены: ${failedFiles.join(', ')}.` : ''
    registryError.value = `Заявка создана, но обновить реестр не удалось. Не создавайте её повторно; обновите страницу.${fileMessage}${commentMessage}`
  } finally {
    showCreate.value = false
    createLoading.value = false
  }
}

function selectDraftFiles(event) {
  draftFiles.value = Array.from(event.target.files || [])
}

async function loadExecutors() {
  try {
    const result = await requestApi.executors()
    executors.value = result.items
  } catch {
    actionError.value = 'Не удалось загрузить список исполнителей.'
  }
}

async function loadExperts() {
  try {
    const result = await requestApi.experts()
    experts.value = result.items
  } catch {
    actionError.value = 'Не удалось загрузить список экспертов.'
  }
}

async function refreshSelected(requestId) {
  if (selected.value?.backendId === requestId) {
    selected.value = withoutStaleActions(selected.value)
  }
  await loadRequests(true)
  if (selected.value?.backendId !== requestId) return
  const refreshed = requests.value.find(item => item.backendId === requestId) || null
  if (!refreshed) {
    closeRequest()
    return
  }
  await loadRequestDetails(refreshed)
}

async function recoverConflict(requestId, message) {
  try {
    await refreshSelected(requestId)
    actionError.value = `${message} Данные обновлены — проверьте актуальный статус.`
  } catch {
    actionError.value = `${message} Не удалось обновить данные; устаревшие действия отключены.`
  }
}

async function setColorMark(color) {
  if (colorLoading.value || color === selected.value.color) return
  const requestId = selected.value.backendId
  const requestToken = colorRequestGuard.begin(requestId)
  colorLoading.value = true
  colorError.value = ''
  try {
    await requestApi.setColor(requestId, color, selected.value.lockVersion)
    if (!colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      colorError.value = 'Метка сохранена, но обновить карточку не удалось.'
    }
  } catch (error) {
    if (!colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      colorError.value = error.status === 403
        ? 'У вас нет права менять цветовую метку.'
        : 'Не удалось сохранить цветовую метку. Повторите попытку.'
    }
  } finally {
    if (colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      colorLoading.value = false
    }
  }
}

async function rejectRequest() {
  if (rejectLoading.value) return
  if (!(await confirmDialog.ask('Отказать в проведении испытаний по этой заявке?', { confirmLabel: 'Отказать' }))) return
  const requestId = selected.value.backendId
  const requestToken = rejectRequestGuard.begin(requestId)
  rejectLoading.value = true
  rejectError.value = ''
  try {
    await requestApi.reject(requestId, selected.value.lockVersion)
    if (!rejectRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!rejectRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      rejectError.value = 'Отказ сохранён, но обновить карточку не удалось.'
    }
  } catch (error) {
    if (!rejectRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      rejectError.value = error.status === 403
        ? 'Отказать в проведении испытаний может только руководитель.'
        : 'Не удалось сохранить отказ. Повторите попытку.'
    }
  } finally {
    if (rejectRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      rejectLoading.value = false
    }
  }
}

async function withdrawRequest() {
  if (withdrawLoading.value) return
  if (!(await confirmDialog.ask('Отозвать эту заявку?', { confirmLabel: 'Отозвать' }))) return
  const requestId = selected.value.backendId
  const requestToken = withdrawRequestGuard.begin(requestId)
  withdrawLoading.value = true
  withdrawError.value = ''
  try {
    await requestApi.withdraw(requestId, selected.value.lockVersion)
    if (!withdrawRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!withdrawRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      withdrawError.value = 'Заявка отозвана, но обновить карточку не удалось.'
    }
  } catch (error) {
    if (!withdrawRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      withdrawError.value = error.status === 403
        ? 'Отозвать заявку может только инициатор.'
        : 'Не удалось отозвать заявку. Повторите попытку.'
    }
  } finally {
    if (withdrawRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      withdrawLoading.value = false
    }
  }
}

async function assignExecutor() {
  if (!executorChoice.value) {
    actionError.value = 'Выберите исполнителя.'
    return
  }
  if (!(await confirmDialog.ask('Назначить выбранного исполнителя на заявку?', { confirmLabel: 'Назначить' }))) return

  actionLoading.value = true
  actionError.value = ''
  const requestId = selected.value.backendId
  try {
    await requestApi.assignExecutor(requestId, Number(executorChoice.value), selected.value.lockVersion)
    try {
      await refreshSelected(requestId)
    } catch {
      actionError.value = 'Исполнитель назначен, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      actionError.value = error.status === 403
        ? 'У вас нет права назначать исполнителя.'
        : 'Не удалось назначить исполнителя. Обновите страницу и повторите попытку.'
    }
  } finally {
    actionLoading.value = false
  }
}

async function claimExpert() {
  if (claimLoading.value) return
  const requestId = selected.value.backendId
  const requestToken = claimRequestGuard.begin(requestId)
  claimLoading.value = true
  claimError.value = ''
  try {
    await requestApi.claimExpert(requestId, selected.value.lockVersion)
    if (!claimRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!claimRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      claimError.value = 'Заявка взята в работу, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (!claimRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      claimError.value = error.status === 403
        ? 'У вас нет права брать эту заявку в работу.'
        : 'Не удалось взять заявку в работу. Обновите страницу и повторите попытку.'
    }
  } finally {
    if (claimRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      claimLoading.value = false
    }
  }
}

async function reassignExpert() {
  if (reassignLoading.value) return
  if (!expertChoice.value) {
    reassignError.value = 'Выберите эксперта.'
    return
  }
  if (!(await confirmDialog.ask('Переназначить заявку выбранному эксперту?', { confirmLabel: 'Переназначить' }))) return

  const requestId = selected.value.backendId
  const requestToken = reassignRequestGuard.begin(requestId)
  reassignLoading.value = true
  reassignError.value = ''
  try {
    await requestApi.reassignExpert(requestId, Number(expertChoice.value), selected.value.lockVersion)
    if (!reassignRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!reassignRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      reassignError.value = 'Заявка переназначена, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (!reassignRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      reassignError.value = error.status === 403
        ? 'У вас нет права переназначать эту заявку.'
        : 'Не удалось переназначить заявку. Обновите страницу и повторите попытку.'
    }
  } finally {
    if (reassignRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      reassignLoading.value = false
    }
  }
}

async function deleteReport() {
  if (deleteReportLoading.value) return
  if (!(await confirmDialog.ask('Удалить загруженный отчёт испытаний? Отчёт и заключение по нему станут недоступны.', { confirmLabel: 'Удалить', danger: true }))) return
  const requestId = selected.value.backendId
  const requestToken = deleteReportRequestGuard.begin(requestId)
  deleteReportLoading.value = true
  deleteReportError.value = ''
  try {
    await requestApi.deleteReport(requestId, selected.value.lockVersion)
    if (!deleteReportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!deleteReportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      deleteReportError.value = 'Отчёт удалён, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (!deleteReportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      deleteReportError.value = error.status === 403
        ? 'Удалить отчёт может только исполнитель или руководитель.'
        : 'Не удалось удалить отчёт. Обновите страницу и повторите попытку.'
    }
  } finally {
    if (deleteReportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      deleteReportLoading.value = false
    }
  }
}

async function publishOpinion() {
  const body = opinionDraft.value.trim()
  if (body.length < 10) {
    opinionError.value = 'Заключение должно содержать не менее 10 символов.'
    return
  }
  if (!(await confirmDialog.ask('Опубликовать заключение и передать заявку на контроль СБ?', { confirmLabel: 'Опубликовать' }))) return

  const requestId = selected.value.backendId
  const requestToken = opinionRequestGuard.begin(requestId)
  opinionLoading.value = true
  opinionError.value = ''
  try {
    await requestApi.publishOpinion(requestId, body, selected.value.lockVersion)
    if (!opinionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    selected.value = { ...selected.value, canPublishOpinion: false }
    opinionDraft.value = ''
    try {
      await refreshSelected(requestId)
    } catch {
      opinionError.value = 'Заключение опубликовано, но обновить карточку не удалось. Не отправляйте его повторно.'
    }
  } catch (error) {
    if (!opinionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      opinionError.value = error.status === 422
        ? 'Заключение должно содержать от 10 до 20 000 символов.'
        : error.status === 403
          ? 'Опубликовать заключение может только назначенный эксперт.'
          : 'Не удалось опубликовать заключение.'
    }
  } finally {
    if (opinionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      opinionLoading.value = false
    }
  }
}

async function decideSecurity(decision) {
  const reason = securityReason.value.trim()
  if (decision === 'return' && !reason) {
    securityError.value = 'Укажите причину возврата заявки.'
    return
  }
  const prompt = decision === 'approve'
    ? 'Согласовать заключение и завершить заявку?'
    : 'Вернуть заявку исполнителю с указанной причиной?'
  const confirmLabel = decision === 'approve' ? 'Согласовать' : 'Вернуть'
  if (!(await confirmDialog.ask(prompt, { confirmLabel }))) return

  const requestId = selected.value.backendId
  const requestToken = securityRequestGuard.begin(requestId)
  securityLoading.value = true
  securityError.value = ''
  try {
    await requestApi.decideSecurity(requestId, decision, reason || null, selected.value.lockVersion)
    if (!securityRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    selected.value = { ...selected.value, canSecurityDecide: false }
    securityReason.value = ''
    try {
      await refreshSelected(requestId)
    } catch {
      securityError.value = 'Решение сохранено, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (!securityRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      securityError.value = error.status === 422
        ? 'Проверьте решение и причину возврата.'
        : error.status === 403
          ? 'Решение может принять только сотрудник СБ на этапе контроля.'
          : 'Не удалось сохранить решение СБ.'
    }
  } finally {
    if (securityRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      securityLoading.value = false
    }
  }
}

async function startRequest() {
  if (!(await confirmDialog.ask('Перевести заявку в работу?', { confirmLabel: 'Начать работу' }))) return

  actionLoading.value = true
  actionError.value = ''
  const requestId = selected.value.backendId
  try {
    await requestApi.start(requestId, selected.value.lockVersion)
    try {
      await refreshSelected(requestId)
    } catch {
      actionError.value = 'Заявка переведена в работу, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      actionError.value = error.status === 403
        ? 'У вас нет права переводить эту заявку в работу.'
        : 'Не удалось перевести заявку в работу. Повторите попытку.'
    }
  } finally {
    actionLoading.value = false
  }
}

onMounted(bootstrapAuth)
</script>

<template>
  <div class="shell">
    <div v-if="authLoading" class="auth-loading">Загрузка…</div>
    <div v-else-if="!authDevMode && !authUser" class="auth-screen">
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
        <label>Логин<input v-model="loginForm.login" autocomplete="username" required :disabled="loginLoading" /></label>
        <label>Пароль<input v-model="loginForm.password" type="password" autocomplete="current-password" required :disabled="loginLoading" /></label>
        <p v-if="loginError" class="form-error">{{ loginError }}</p>
        <button class="primary" type="submit" :disabled="loginLoading">{{ loginLoading ? 'Вход…' : 'Войти' }}</button>
      </form>
    </div>
    <template v-else>
      <main>
        <header class="topbar">
          <div class="topbar-inner">
            <div class="brand-block">
              <button
                type="button"
                class="brand-mark-btn"
                title="На главную"
                :disabled="!selected"
                @click="closeRequest"
              >
                <svg class="brand-mark" width="48" height="48" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                  <rect x="2" y="2" width="36" height="36" rx="10" fill="currentColor" />
                  <path d="M12 25a8 8 0 1 1 16 0" stroke="#fff" stroke-width="2" stroke-linecap="round" />
                  <path d="M12 25h2M26 25h2M20 15v2" stroke="#fff" stroke-width="1.6" stroke-linecap="round" />
                  <path d="M20 25l5-6.5" stroke="#fff" stroke-width="2" stroke-linecap="round" />
                  <circle cx="20" cy="25" r="1.6" fill="#fff" />
                </svg>
              </button>
              <div>
                <p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p>
                <h1>{{ selected ? `Заявка ${selected.id}` : 'Заявки на проведение испытаний' }}</h1>
                <p v-if="!selected" class="tagline">Регистрация, испытания и согласование результатов</p>
              </div>
            </div>
            <div class="profile">
              <select
                v-if="authDevMode"
                class="dev-user-switch"
                title="Dev-переключатель пользователя (только APP_ENV=dev)"
                :value="devUserId"
                @change="switchDevUser($event.target.value)"
              >
                <option v-for="user in DEV_USERS" :key="user.id" :value="user.id">{{ user.displayName }} — {{ user.position }}</option>
              </select>
              <span class="avatar">{{ currentInitials }}</span>
              <span><b>{{ currentProfile.displayName }}</b><small>{{ currentProfile.position }}</small></span>
              <button v-if="isAdministrator" type="button" class="secondary" @click="openAdmin">Администрирование</button>
              <button v-if="!authDevMode" type="button" class="secondary" @click="logout">Выйти</button>
            </div>
          </div>
        </header>

        <section v-if="showAdmin" class="page admin-page">
          <div class="card">
            <div class="section-title"><h3>Пользователи и роли</h3><button class="secondary" @click="closeAdmin">← К реестру заявок</button></div>
            <p v-if="adminError" class="detail-state error">{{ adminError }}</p>
            <p v-if="adminLoading" class="detail-state">Загрузка…</p>
            <form v-else class="admin-create-user" @submit.prevent="createAdminUser">
              <label>Логин AD<input v-model="newUserAdLogin" placeholder="ivanov" :disabled="createUserLoading" /></label>
              <label>Отображаемое имя<input v-model="newUserDisplayName" placeholder="Иван Иванов" :disabled="createUserLoading" /></label>
              <button class="primary" :disabled="createUserLoading">{{ createUserLoading ? 'Добавление…' : 'Добавить заранее' }}</button>
            </form>
            <p v-if="createUserError" class="action-error">{{ createUserError }}</p>
            <p class="hint">Заведённый заранее профиль автоматически получит роль «Сотрудник» и найдётся по
              этому же логину при первом реальном входе через LDAP — назначенные ниже роли сохранятся.</p>
            <p v-if="roleActionError" class="action-error">{{ roleActionError }}</p>
            <div v-if="!adminLoading" class="table-wrap">
              <table>
                <thead><tr><th>ФИО</th><th>Логин AD</th><th>Email</th><th>Активен</th><th>Роли</th></tr></thead>
                <tbody>
                  <tr v-for="user in adminUsers" :key="user.id">
                    <td><b>{{ user.displayName }}</b></td>
                    <td>{{ user.adLogin }}</td>
                    <td>{{ user.email || '—' }}</td>
                    <td>{{ user.isActive ? 'да' : 'нет' }}</td>
                    <td>
                      <span v-for="role in user.roles" :key="role.id" class="role-chip">
                        {{ role.name }}
                        <button type="button" title="Отозвать роль" @click="revokeAdminRole(user.id, role.id)">×</button>
                      </span>
                      <span class="role-assign">
                        <select v-model="roleChoiceByUser[user.id]">
                          <option value="">Добавить роль…</option>
                          <option v-for="role in adminRoles" :key="role.id" :value="role.id">{{ role.name }}</option>
                        </select>
                        <button type="button" class="secondary" @click="assignAdminRole(user.id)">+</button>
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section v-else-if="!selected" class="page">
          <p v-if="registryError" class="detail-state error">{{ registryError }}</p>

          <div class="card registry">
            <div class="tabs">
              <button v-for="tab in tabs" :key="tab.id" :class="{active: activeTab === tab.id}" @click="activeTab = tab.id">
                {{ tab.label }} <span>{{ tab.count }}</span>
              </button>
              <button class="primary tabs-cta" @click="showCreate = true">＋ Новая заявка</button>
            </div>
            <div class="toolbar">
              <label class="search">⌕ <input v-model="query" placeholder="Поиск по заявкам" /></label>
              <select v-model="statusFilter"><option value="">Все статусы</option><option v-for="status in statuses" :key="status">{{ status }}</option></select>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th class="sortable" @click="toggleSort">№ заявки {{ sortDirection === 'desc' ? '↓' : '↑' }}</th><th>Дата</th><th>Объект испытаний</th><th>Инициатор</th><th>Исполнитель</th><th>Статус</th><th>Отметка СБ</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="item in paged.items" :key="item.id" :class="'row-color-' + item.color" @click="openRequest(item)">
                    <td class="number">{{ item.id }}</td><td>{{ item.date }}</td>
                    <td><b>{{ item.product }}</b><small>{{ item.supplier }}</small></td>
                    <td>{{ item.initiator }}<small>{{ item.department }}</small></td>
                    <td>{{ item.executor }}</td><td><span class="badge" :class="item.tone">{{ item.status }}</span></td>
                    <td>{{ item.securityMark }}</td><td>›</td>
                  </tr>
                </tbody>
              </table>
              <div v-if="!filtered.length" class="empty"><div>⌕</div><h3>Ничего не найдено</h3><p>Измените запрос или очистите фильтры</p></div>
            </div>
            <footer v-if="filtered.length" class="pagination">
              <span>{{ (paged.page - 1) * pageSize + 1 }}–{{ Math.min(paged.page * pageSize, paged.total) }} из {{ paged.total }}</span>
              <span>
                <button :disabled="paged.page <= 1" @click="currentPage = paged.page - 1">‹</button>
                <button v-for="pageNumber in pageNumbers" :key="pageNumber" :class="{ current: pageNumber === paged.page }" @click="currentPage = pageNumber">{{ pageNumber }}</button>
                <button :disabled="paged.page >= paged.pageCount" @click="currentPage = paged.page + 1">›</button>
              </span>
            </footer>
          </div>
        </section>

        <section v-else class="page request-page">
          <div class="request-actions">
            <button class="back" @click="closeRequest">‹</button>
            <span class="badge" :class="selected.tone">{{ selected.status }}</span>
          </div>
          <p v-if="detailLoading" class="detail-state">Загрузка актуальной карточки…</p>
          <p v-if="detailError" class="detail-state error">{{ detailError }}</p>
          <div class="request-grid">
            <div class="stack">
              <article v-if="hasHeroAction" class="card hero">
                <p class="hero-eyebrow">Доступные действия</p>

                <div v-if="selected.canAssignExecutor" class="hero-block">
                  <h4>Назначить исполнителя</h4>
                  <label>Исполнитель ИЦ<select v-model="executorChoice" :disabled="actionLoading"><option value="">Выберите сотрудника</option><option v-for="executor in executors" :key="executor.id" :value="executor.id">{{ executor.displayName }}</option></select></label>
                  <div class="hero-actions"><button class="primary big" :disabled="actionLoading" @click="assignExecutor">{{ actionLoading ? 'Сохранение…' : 'Назначить' }}</button></div>
                  <a class="help-link" href="/help/assignment.html" target="_blank">Инструкция по назначению и началу работы</a>
                </div>

                <div v-if="selected.canStart" class="hero-block">
                  <h4>Начать работу</h4>
                  <p class="hero-sub">Заявка перейдёт в статус «В работе»</p>
                  <div class="hero-actions"><button class="primary big" :disabled="actionLoading" @click="startRequest">{{ actionLoading ? 'Запуск…' : 'Начать работу' }}</button></div>
                  <a class="help-link" href="/help/assignment.html" target="_blank">Инструкция по назначению и началу работы</a>
                </div>
                <p v-if="actionError" class="action-error">{{ actionError }}</p>

                <div v-if="selected.canUploadReport" class="hero-block">
                  <h4>{{ selected.canDeleteReport ? 'Загрузить новую версию отчёта' : 'Загрузите отчёт испытаний' }}</h4>
                  <p class="hero-sub">{{ selected.canDeleteReport ? 'Текущий отчёт не удаляется — новая версия добавляется поверх' : 'Заявка перейдёт на подготовку экспертного заключения сразу после загрузки' }}</p>
                  <div class="hero-actions"><label class="primary upload-button big">{{ reportLoading ? 'Загрузка отчёта…' : 'Загрузить отчёт испытаний' }}<input type="file" :disabled="reportLoading" accept=".pdf,application/pdf" @change="uploadReport" /></label></div>
                  <p v-if="reportError" class="action-error">{{ reportError }}</p>
                  <a class="help-link" href="/help/report.html" target="_blank">Инструкция по загрузке отчёта испытаний</a>
                </div>

                <div v-if="selected.canClaimExpert" class="hero-block">
                  <h4>Взять заявку в работу</h4>
                  <p class="hero-sub">Вы станете экспертом, готовящим заключение по этой заявке</p>
                  <div class="hero-actions"><button class="primary big" :disabled="claimLoading" @click="claimExpert">{{ claimLoading ? 'Сохранение…' : 'Взять в работу' }}</button></div>
                  <p v-if="claimError" class="action-error">{{ claimError }}</p>
                  <a class="help-link" href="/help/expert-opinion.html" target="_blank">Инструкция по формированию заключения</a>
                </div>

                <div v-if="selected.canPublishOpinion" class="hero-block">
                  <h4>Экспертное заключение</h4>
                  <p class="hero-sub">Заявка перейдёт на контроль СБ сразу после публикации</p>
                  <textarea v-model="opinionDraft" :disabled="opinionLoading" minlength="10" maxlength="20000" placeholder="Введите итоговое заключение по результатам испытаний"></textarea>
                  <div class="hero-actions"><button class="primary big" :disabled="opinionLoading" @click="publishOpinion">{{ opinionLoading ? 'Публикация…' : 'Опубликовать и передать в СБ' }}</button></div>
                  <p v-if="opinionError" class="action-error">{{ opinionError }}</p>
                  <a class="help-link" href="/help/expert-opinion.html" target="_blank">Инструкция по формированию заключения</a>
                </div>

                <div v-if="selected.canSecurityDecide" class="hero-block">
                  <h4>Решение по заключению</h4>
                  <div class="hero-actions"><button class="primary big confirm" :disabled="securityLoading" @click="decideSecurity('approve')">{{ securityLoading ? 'Сохранение…' : 'Согласовать и завершить' }}</button><button class="secondary big" :disabled="securityLoading" @click="decideSecurity('return')">Вернуть в работу</button></div>
                  <label>Комментарий<textarea v-model="securityReason" :disabled="securityLoading" maxlength="5000" placeholder="Обязателен при возврате заявки"></textarea></label>
                  <p v-if="securityError" class="action-error">{{ securityError }}</p>
                  <a class="help-link" href="/help/security-review.html" target="_blank">Инструкция по контролю СБ</a>
                </div>

                <div v-if="selected.canReassignExpert" class="hero-block hero-block-compact">
                  <h4>Переназначить эксперту</h4>
                  <div class="reassign-row">
                    <select v-model="expertChoice" :disabled="reassignLoading" aria-label="Новый эксперт"><option value="">Выберите эксперта</option><option v-for="expert in experts.filter(candidate => candidate.id !== selected.expertId)" :key="expert.id" :value="expert.id">{{ expert.displayName }}</option></select>
                    <button class="secondary" :disabled="reassignLoading" @click="reassignExpert">{{ reassignLoading ? 'Сохранение…' : 'Переназначить' }}</button>
                  </div>
                  <p v-if="reassignError" class="action-error">{{ reassignError }}</p>
                </div>

                <div v-if="selected.canReject || selected.canWithdraw || selected.canDeleteReport" class="hero-block hero-secondary">
                  <button v-if="selected.canReject" class="secondary action-wide" :disabled="rejectLoading" @click="rejectRequest">{{ rejectLoading ? 'Сохранение…' : 'Отказать в проведении испытаний' }}</button>
                  <p v-if="rejectError" class="action-error">{{ rejectError }}</p>
                  <button v-if="selected.canWithdraw" class="secondary action-wide" :disabled="withdrawLoading" @click="withdrawRequest">{{ withdrawLoading ? 'Сохранение…' : 'Отозвать заявку' }}</button>
                  <p v-if="withdrawError" class="action-error">{{ withdrawError }}</p>
                  <button v-if="selected.canDeleteReport" class="secondary action-wide" :disabled="deleteReportLoading" @click="deleteReport">{{ deleteReportLoading ? 'Удаление…' : 'Удалить отчёт' }}</button>
                  <p v-if="deleteReportError" class="action-error">{{ deleteReportError }}</p>
                </div>
              </article>

              <article class="card feed">
                <div class="section-title"><h3>Лента заявки <span>{{ feed.length }}</span></h3></div>
                <button v-if="selected.commentsPage?.hasMore" class="secondary" :disabled="olderCommentsLoading" @click="loadOlderComments">{{ olderCommentsLoading ? 'Загрузка…' : 'Показать предыдущие' }}</button>
                <div class="stream">
                  <div v-for="entry in feed" :key="`${entry.type}-${entry.id}`" class="entry" :class="{ system: entry.type === 'milestone' }">
                    <span class="avatar small" :class="{ 'blue-avatar': entry.type === 'comment' }">●</span>
                    <div class="entry-body">
                      <template v-if="entry.type === 'milestone'">
                        <div class="entry-head"><b>{{ entry.actor }} — {{ entry.description }}</b><time>{{ entry.occurredAt }} · {{ entry.ruleId }}</time></div>
                      </template>
                      <template v-else>
                        <div class="entry-head"><b>{{ entry.author }}</b><time>{{ entry.createdAt }}</time></div>
                        <p>{{ entry.body }}</p>
                      </template>
                    </div>
                  </div>
                </div>
                <p v-if="!feed.length" class="placeholder-copy">Лента пока пуста.</p>
                <form v-if="canSubmitComment(selected, detailLoading)" class="comment-input" @submit.prevent="addComment"><span class="avatar small">МУ</span><input v-model="commentDraft" :disabled="commentLoading" maxlength="10000" placeholder="Оставьте комментарий…" /><button :disabled="commentLoading">➤</button></form>
                <p v-else class="placeholder-copy">На текущем этапе новые комментарии недоступны.</p>
                <p v-if="commentError" class="action-error">{{ commentError }}</p>
              </article>
            </div>
            <aside class="stack side-column">
              <article class="card summary"><h3>Статус</h3>
                <p><span>Исполнитель</span><b>{{ selected.executor }}</b></p>
                <p><span>Эксперт</span><b>{{ selected.expert }}</b></p>
                <p><span>Отметка СБ</span><b>{{ selected.securityMark }}</b></p>
                <div v-if="selected.canSetColor" class="color-picker">
                  <button
                    v-for="color in REQUEST_COLORS"
                    :key="color"
                    type="button"
                    class="color-swatch"
                    :class="[color, { active: selected.color === color }]"
                    :disabled="colorLoading"
                    :title="color"
                    @click="setColorMark(color)"
                  ></button>
                </div>
                <p v-if="colorError" class="action-error">{{ colorError }}</p>
              </article>
              <article class="card summary"><h3>Объект испытаний</h3>
                <p><span>Инициатор</span><b>{{ selected.initiator }}</b></p>
                <p><span>Подразделение</span><b>{{ selected.department }}</b></p>
                <p><span>Производитель</span><b>{{ selected.manufacturer || '—' }}</b></p>
                <p><span>Поставщик</span><b>{{ selected.supplier }}</b></p>
                <p><span>Количество образцов</span><b>{{ selected.sampleQuantity || '—' }} шт.</b></p>
                <p class="wide"><span>Наименование и тип</span><b>{{ selected.product }}</b></p>
                <p class="wide"><span>Метод испытаний</span><b>{{ selected.testMethod || '—' }}</b></p>
              </article>
              <article class="card documents"><h3>Документы <span>{{ selected.documents?.length || 0 }}</span></h3>
                <button v-for="document in selected.documents || []" :key="document.versionId" class="document-row" @click="downloadDocument(document)"><span>▣</span><span><b>{{ document.title }}</b><small>Версия {{ document.version }} · {{ document.size }} · {{ document.createdAt }}</small></span></button>
                <p v-if="!selected.documents?.length" class="placeholder-copy">Документов пока нет.</p>
                <label v-if="selected.canUploadDocument" class="secondary upload-button">{{ documentLoading ? 'Загрузка…' : 'Загрузить документ' }}<input type="file" :disabled="documentLoading" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" @change="uploadDocument" /></label>
                <p v-if="documentError" class="action-error">{{ documentError }}</p>
              </article>
            </aside>
          </div>
        </section>
      </main>

      <div v-if="showCreate" class="overlay" @click.self="!createLoading && (showCreate = false)">
        <form class="modal" @submit.prevent="createRequest">
          <div class="modal-head"><div><p class="eyebrow">Новая заявка</p><h2>Проведение испытаний</h2></div><button type="button" :disabled="createLoading" @click="showCreate = false">×</button></div>
          <div class="form-grid">
            <label>Наименование и тип *<input v-model="draft.productName" required placeholder="Введите наименование продукции" /></label>
            <label>Количество образцов *<input v-model.number="draft.sampleQuantity" required type="number" min="1" /></label>
            <label>Производитель *<input v-model="draft.manufacturer" required placeholder="Наименование производителя" /></label>
            <label>Поставщик *<input v-model="draft.supplier" required placeholder="Наименование поставщика" /></label>
            <label class="wide">Метод испытаний *<textarea v-model="draft.testMethod" required placeholder="Опишите метод или программу испытаний"></textarea></label>
            <label class="wide">Сопроводительная документация<div class="dropzone"><input type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" :disabled="createLoading" @change="selectDraftFiles" /><span>Перетащите файлы сюда или <b>выберите на компьютере</b></span><small v-if="draftFiles.length">Выбрано: {{ draftFiles.map(file => file.name).join(', ') }}</small></div></label>
            <label class="wide">Комментарий<textarea v-model="draft.comment" :disabled="createLoading" maxlength="10000" placeholder="Дополнительная информация"></textarea></label>
          </div>
          <p v-if="createError" class="form-error">{{ createError }}</p>
          <div class="modal-actions"><button type="button" class="secondary" :disabled="createLoading" @click="showCreate = false">Отмена</button><button class="primary" :disabled="createLoading">{{ createLoading ? 'Создание…' : 'Создать заявку' }}</button></div>
        </form>
      </div>

      <div v-if="confirmDialog.state.open" class="overlay" @click.self="confirmDialog.cancel">
        <div class="modal confirm-modal">
          <p>{{ confirmDialog.state.message }}</p>
          <div class="modal-actions">
            <button type="button" class="secondary" @click="confirmDialog.cancel">Отмена</button>
            <button type="button" class="primary" :class="{ danger: confirmDialog.state.danger }" @click="confirmDialog.accept">{{ confirmDialog.state.confirmLabel }}</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
