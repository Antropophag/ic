<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { requestApi } from './api'
import { createLatestRequestGuard } from './latestRequestGuard'
import { ACTIVE_STATUSES, canSubmitComment, commentFromApi, documentFromApi, filterRequests, fromApi, historyFromApi } from './registry'

const activeTab = ref('active')
const query = ref('')
const statusFilter = ref('')
const selected = ref(null)
const showCreate = ref(false)
const showHistory = ref(false)
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
const detailRequestGuard = createLatestRequestGuard()
const commentRequestGuard = createLatestRequestGuard()
const commentsPageRequestGuard = createLatestRequestGuard()
const documentRequestGuard = createLatestRequestGuard()
const reportRequestGuard = createLatestRequestGuard()
const opinionRequestGuard = createLatestRequestGuard()
const draft = reactive({
  productName: '', manufacturer: '', supplier: '', sampleQuantity: 1, testMethod: '',
})

const requests = ref([
  { id: '000146', date: '27.07.2026', initiator: 'Максим Умнов', department: 'Бюро приводной техники', product: 'Лебёдка Furder VT40K', supplier: 'ООО «Вектор Технологий»', executor: 'С. И. Кашин', status: 'Заявка зарегистрирована', tone: 'blue' },
  { id: '000145', date: '27.07.2026', initiator: 'Виктор Медведев', department: 'Отдел производственных закупок', product: 'IP-видеокамера DS-2CD2543G2-IS', supplier: 'ООО «Видеотехнология»', executor: 'С. В. Наумов', status: 'Заявка в работе', tone: 'cyan' },
  { id: '000144', date: '24.07.2026', initiator: 'Андрей Соколов', department: 'Отдел главного конструктора', product: 'Ограничитель скорости ОС-2', supplier: 'АО «Лифткомплект»', executor: 'С. В. Прикуль', status: 'Работы приостановлены', tone: 'orange' },
  { id: '000143', date: '22.07.2026', initiator: 'Елена Орлова', department: 'Служба закупок', product: 'Частотный преобразователь 15 кВт', supplier: 'ООО «Электропривод»', executor: 'С. Д. Шапошников', status: 'Подготовка заключения', tone: 'violet' },
  { id: '000142', date: '21.07.2026', initiator: 'Павел Зимин', department: 'Управление качества', product: 'Буфер полиуретановый БП-100', supplier: 'ООО «Полимер»', executor: 'В. Я. Галкин', status: 'Контроль СБ', tone: 'yellow' },
  { id: '000141', date: '18.07.2026', initiator: 'Ирина Белова', department: 'Служба закупок', product: 'Датчик положения кабины', supplier: 'ООО «Сенсорика»', executor: 'В. В. Козлов', status: 'Заявка выполнена', tone: 'green' },
])

const tabs = computed(() => [
  { id: 'active', label: 'Активные заявки', count: requests.value.filter(item => ACTIVE_STATUSES.includes(item.status)).length },
  { id: 'all', label: 'Все заявки', count: requests.value.length },
  { id: 'mine', label: 'Мои заявки', count: requests.value.filter(item => item.initiator === 'Максим Умнов').length },
])
const statuses = computed(() => [...new Set(requests.value.map(item => item.status))])
const filtered = computed(() => filterRequests(requests.value, {
  tab: activeTab.value,
  query: query.value,
  status: statusFilter.value,
  currentUser: 'Максим Умнов',
}))

async function loadRequestDetails(item) {
  const requestToken = detailRequestGuard.begin(item.backendId)
  selected.value = item
  detailError.value = ''
  detailLoading.value = true
  executorChoice.value = item.executorId || ''
  expertChoice.value = item.expertId || ''
  if (item.canAssignExecutor && !executors.value.length) loadExecutors()
  if (item.canAssignExpert && !experts.value.length) loadExperts()
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
    expertChoice.value = selected.value.expertId || ''
    if (selected.value.canAssignExecutor && !executors.value.length) loadExecutors()
    if (selected.value.canAssignExpert && !experts.value.length) loadExperts()
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
  showHistory.value = false
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
  selected.value = null
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
    reportError.value = error.status === 422
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
    documentError.value = error.status === 422
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
  try {
    const result = await requestApi.list()
    requests.value = result.items.map(fromApi)
  } catch (error) {
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
      : 'Не удалось создать заявку. Повторите попытку.'
    createLoading.value = false
    return
  }

  const failedFiles = []
  for (const file of draftFiles.value) {
    try {
      await requestApi.uploadDocument(created.id, file)
    } catch {
      failedFiles.push(file.name)
    }
  }
  Object.assign(draft, { productName: '', manufacturer: '', supplier: '', sampleQuantity: 1, testMethod: '' })
  draftFiles.value = []
  try {
    await loadRequests(true)
    const createdItem = requests.value.find(item => item.backendId === created.id)
    if (createdItem) {
      await openRequest(createdItem)
      if (failedFiles.length) {
        documentError.value = `Заявка создана, но не удалось загрузить: ${failedFiles.join(', ')}.`
      }
    } else {
      registryError.value = 'Заявка создана, но пока не появилась в реестре. Не создавайте её повторно; обновите страницу.'
    }
  } catch {
    const fileMessage = failedFiles.length ? ` Не загружены: ${failedFiles.join(', ')}.` : ''
    registryError.value = `Заявка создана, но обновить реестр не удалось. Не создавайте её повторно; обновите страницу.${fileMessage}`
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
    selected.value = {
      ...selected.value,
      canAssignExecutor: false,
      canAssignExpert: false,
      canPublishOpinion: false,
      canStart: false,
    }
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

async function assignExecutor() {
  if (!executorChoice.value) {
    actionError.value = 'Выберите исполнителя.'
    return
  }
  if (!window.confirm('Назначить выбранного исполнителя на заявку?')) return

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

async function assignExpert() {
  if (!expertChoice.value) {
    actionError.value = 'Выберите эксперта.'
    return
  }
  if (!window.confirm('Назначить выбранного эксперта на заявку?')) return

  actionLoading.value = true
  actionError.value = ''
  const requestId = selected.value.backendId
  try {
    await requestApi.assignExpert(requestId, Number(expertChoice.value), selected.value.lockVersion)
    try {
      await refreshSelected(requestId)
    } catch {
      actionError.value = 'Эксперт назначен, но обновить карточку не удалось. Устаревшие действия отключены.'
    }
  } catch (error) {
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      actionError.value = error.status === 403
        ? 'У вас нет права назначать эксперта.'
        : 'Не удалось назначить эксперта. Обновите страницу и повторите попытку.'
    }
  } finally {
    actionLoading.value = false
  }
}

async function publishOpinion() {
  const body = opinionDraft.value.trim()
  if (body.length < 10) {
    opinionError.value = 'Заключение должно содержать не менее 10 символов.'
    return
  }
  if (!window.confirm('Опубликовать заключение и передать заявку на контроль СБ?')) return

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

async function startRequest() {
  if (!window.confirm('Перевести заявку в работу?')) return

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

onMounted(loadRequests)
</script>

<template>
  <div class="shell">
    <main>
      <header class="topbar">
        <div class="topbar-inner">
          <div class="brand-block">
            <span class="brand-mark">Щ</span>
            <div>
              <p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p>
              <h1>{{ selected ? `Заявка ${selected.id}` : 'Регистратор испытаний' }}</h1>
            </div>
          </div>
          <nav class="topnav">
            <button class="active">Заявки</button>
            <button>Справочники</button>
          </nav>
          <div class="profile">
            <span class="avatar">МУ</span>
            <span><b>Максим Умнов</b><small>Бюро приводной техники</small></span>
          </div>
        </div>
      </header>

      <section v-if="!selected" class="page">
        <div class="page-actions">
          <div>
            <h2>Заявки на проведение испытаний</h2>
            <p>Регистрация, испытания и согласование результатов</p>
          </div>
          <button class="primary" @click="showCreate = true">＋ Новая заявка</button>
        </div>
        <p v-if="registryError" class="detail-state error">{{ registryError }}</p>

        <div class="card registry">
          <div class="tabs">
            <button v-for="tab in tabs" :key="tab.id" :class="{active: activeTab === tab.id}" @click="activeTab = tab.id">
              {{ tab.label }} <span>{{ tab.count }}</span>
            </button>
          </div>
          <div class="toolbar">
            <label class="search">⌕ <input v-model="query" placeholder="Поиск по заявкам" /></label>
            <select v-model="statusFilter"><option value="">Все статусы</option><option v-for="status in statuses" :key="status">{{ status }}</option></select>
            <button class="secondary">☷ Настроить таблицу</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>№ заявки</th><th>Дата</th><th>Объект испытаний</th><th>Инициатор</th><th>Исполнитель</th><th>Статус</th><th></th></tr></thead>
              <tbody>
                <tr v-for="item in filtered" :key="item.id" @click="openRequest(item)">
                  <td class="number">{{ item.id }}</td><td>{{ item.date }}</td>
                  <td><b>{{ item.product }}</b><small>{{ item.supplier }}</small></td>
                  <td>{{ item.initiator }}<small>{{ item.department }}</small></td>
                  <td>{{ item.executor }}</td><td><span class="badge" :class="item.tone">{{ item.status }}</span></td><td>›</td>
                </tr>
              </tbody>
            </table>
            <div v-if="!filtered.length" class="empty"><div>⌕</div><h3>Ничего не найдено</h3><p>Измените запрос или очистите фильтры</p></div>
          </div>
          <footer class="pagination"><span>1–{{ filtered.length }} из {{ filtered.length }}</span><span><button>‹</button><button class="current">1</button><button>2</button><button>3</button><button>›</button></span></footer>
        </div>
      </section>

      <section v-else class="page request-page">
        <div class="request-actions">
          <button class="back" @click="closeRequest">‹</button>
          <span class="badge" :class="selected.tone">{{ selected.status }}</span>
          <button class="secondary" @click="showHistory = true">◷ История</button>
          <button class="primary">Действия ▾</button>
        </div>
        <p v-if="detailLoading" class="detail-state">Загрузка актуальной карточки…</p>
        <p v-if="detailError" class="detail-state error">{{ detailError }}</p>
        <div class="request-grid">
          <div class="stack">
            <article class="card details">
              <h3>Общая информация</h3>
              <dl>
                <div><dt>Инициатор</dt><dd>{{ selected.initiator }}</dd></div><div><dt>Подразделение</dt><dd>{{ selected.department }}</dd></div>
                <div><dt>Наименование и тип</dt><dd>{{ selected.product }}</dd></div><div><dt>Производитель</dt><dd>{{ selected.manufacturer || '—' }}</dd></div>
                <div><dt>Поставщик</dt><dd>{{ selected.supplier }}</dd></div><div><dt>Количество образцов</dt><dd>{{ selected.sampleQuantity || '—' }} шт.</dd></div>
                <div class="wide"><dt>Метод испытаний</dt><dd>{{ selected.testMethod || '—' }}</dd></div>
              </dl>
            </article>
            <article class="card comments">
              <div class="section-title"><h3>Обсуждение <span>{{ selected.comments?.length || 0 }}</span></h3></div>
              <button v-if="selected.commentsPage?.hasMore" class="secondary" :disabled="olderCommentsLoading" @click="loadOlderComments">{{ olderCommentsLoading ? 'Загрузка…' : 'Показать предыдущие' }}</button>
              <div v-for="comment in selected.comments || []" :key="comment.id" class="comment"><span class="avatar small">●</span><div><b>{{ comment.author }}</b><time>{{ comment.createdAt }}</time><p>{{ comment.body }}</p></div></div>
              <p v-if="!selected.comments?.length" class="placeholder-copy">Комментариев пока нет.</p>
              <form v-if="canSubmitComment(selected, detailLoading)" class="comment-input" @submit.prevent="addComment"><span class="avatar small">МУ</span><input v-model="commentDraft" :disabled="commentLoading" maxlength="10000" placeholder="Оставьте комментарий…" /><button :disabled="commentLoading">➤</button></form>
              <p v-else class="placeholder-copy">На текущем этапе новые комментарии недоступны.</p>
              <p v-if="commentError" class="action-error">{{ commentError }}</p>
            </article>
          </div>
          <aside class="stack side-column">
            <article class="card summary"><h3>Исполнение</h3><p><span>Исполнитель</span><b>{{ selected.executor }}</b></p><p><span>Эксперт</span><b>{{ selected.expert }}</b></p><p><span>Отметка СБ</span><b>—</b></p>
              <div v-if="selected.canAssignExecutor" class="execution-action">
                <label>Назначить исполнителя<select v-model="executorChoice" :disabled="actionLoading"><option value="">Выберите сотрудника</option><option v-for="executor in executors" :key="executor.id" :value="executor.id">{{ executor.displayName }}</option></select></label>
                <button class="secondary" :disabled="actionLoading" @click="assignExecutor">{{ actionLoading ? 'Сохранение…' : 'Назначить' }}</button>
              </div>
              <div v-if="selected.canAssignExpert" class="execution-action">
                <label>Назначить эксперта<select v-model="expertChoice" :disabled="actionLoading"><option value="">Выберите сотрудника</option><option v-for="expert in experts" :key="expert.id" :value="expert.id">{{ expert.displayName }}</option></select></label>
                <button class="secondary" :disabled="actionLoading" @click="assignExpert">{{ actionLoading ? 'Сохранение…' : 'Назначить' }}</button>
              </div>
              <button v-if="selected.canStart" class="primary action-wide" :disabled="actionLoading" @click="startRequest">{{ actionLoading ? 'Запуск…' : 'Начать работу' }}</button>
              <div v-if="selected.canPublishOpinion" class="execution-action">
                <label>Экспертное заключение<textarea v-model="opinionDraft" :disabled="opinionLoading" minlength="10" maxlength="20000" placeholder="Введите итоговое заключение по результатам испытаний"></textarea></label>
                <button class="primary action-wide" :disabled="opinionLoading" @click="publishOpinion">{{ opinionLoading ? 'Публикация…' : 'Опубликовать и передать в СБ' }}</button>
              </div>
              <p v-if="opinionError" class="action-error">{{ opinionError }}</p>
              <p v-if="actionError" class="action-error">{{ actionError }}</p>
              <a class="help-link" href="/help/assignment.html" target="_blank">Инструкция по назначению и началу работы</a>
            </article>
            <article class="card documents"><h3>Документы <span>{{ selected.documents?.length || 0 }}</span></h3>
              <label v-if="selected.canUploadReport" class="primary upload-button">{{ reportLoading ? 'Загрузка отчёта…' : 'Загрузить отчёт испытаний' }}<input type="file" :disabled="reportLoading" accept=".pdf,application/pdf" @change="uploadReport" /></label>
              <p v-if="reportError" class="action-error">{{ reportError }}</p>
              <button v-for="document in selected.documents || []" :key="document.versionId" class="document-row" @click="downloadDocument(document)"><span>▣</span><span><b>{{ document.title }}</b><small>Версия {{ document.version }} · {{ document.size }} · {{ document.createdAt }}</small></span></button>
              <p v-if="!selected.documents?.length" class="placeholder-copy">Документов пока нет.</p>
              <label v-if="selected.canUploadDocument" class="secondary upload-button">{{ documentLoading ? 'Загрузка…' : 'Загрузить документ' }}<input type="file" :disabled="documentLoading" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" @change="uploadDocument" /></label>
              <p v-if="documentError" class="action-error">{{ documentError }}</p>
            </article>
            <article class="card timeline"><h3>Последние события</h3><p v-for="event in (selected.history || []).slice(0, 4)" :key="event.id" class="done">{{ event.description }}<small>{{ event.occurredAt }}</small></p><p v-if="!selected.history?.length">История пока пуста</p></article>
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
          <label class="wide">Комментарий<textarea placeholder="Дополнительная информация"></textarea></label>
        </div>
        <p v-if="createError" class="form-error">{{ createError }}</p>
        <div class="modal-actions"><button type="button" class="secondary" :disabled="createLoading" @click="showCreate = false">Отмена</button><button class="primary" :disabled="createLoading">{{ createLoading ? 'Создание…' : 'Создать заявку' }}</button></div>
      </form>
    </div>

    <div v-if="showHistory" class="overlay drawer-overlay" @click.self="showHistory = false">
      <aside class="drawer"><div class="modal-head"><h2>История изменений</h2><button @click="showHistory = false">×</button></div>
        <div class="history"><div v-for="event in selected.history || []" :key="event.id"><b>{{ event.actor }}</b><p>{{ event.description }} · {{ event.ruleId }}</p><time>{{ event.occurredAt }}</time></div><p v-if="!selected.history?.length" class="placeholder-copy">История пока пуста.</p></div>
      </aside>
    </div>
  </div>
</template>
