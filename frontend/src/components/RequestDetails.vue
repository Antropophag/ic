<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'
import HelpArticle from './HelpArticle.vue'
import { createConfirmDialog } from '../confirmDialog'
import { confirmRequestAction } from '../confirmRequestAction'
import { triggerBlobDownload } from '../download'
import { createLatestRequestGuard } from '../latestRequestGuard'
import { REQUEST_COLORS, avatarRoleClass, canStartNow, canSubmitComment, commentFromApi, documentFromApi, documentKind, fromApi, historyFromApi, initialsFor, newestFirstFeed, withoutStaleActions } from '../registry'
import { shlzStatusToneClass } from '../shlzRegistry'
import { useUiMode } from '../uiMode'

const props = defineProps({ requestId: { type: Number, required: true }, currentInitials: { type: String, default: '' }, initialWarning: { type: String, default: '' } })
const emit = defineEmits(['loaded', 'unavailable', 'updated', 'close'])
const uiMode = useUiMode()
const isShlzMode = uiMode.shlz
const selected = ref(null)
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
const showOpinionModal = ref(false)
const showDepartmentModal = ref(false)
const showAuditDrawer = ref(false)
const showHelpDrawer = ref(false)
const auditTrigger = ref(null)
const auditDrawer = ref(null)
const helpTrigger = ref(null)
const helpDrawer = ref(null)
const colorMenu = ref(null)
const departmentDraft = ref('')
const departmentLoading = ref(false)
const departmentError = ref('')
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
const suspendResumeLoading = ref(false)
const suspendResumeError = ref('')
const startHintRevealed = ref(false)
const detailRequestGuard = createLatestRequestGuard()
const commentRequestGuard = createLatestRequestGuard()
const commentsPageRequestGuard = createLatestRequestGuard()
const documentRequestGuard = createLatestRequestGuard()
const reportRequestGuard = createLatestRequestGuard()
const opinionRequestGuard = createLatestRequestGuard()
const securityRequestGuard = createLatestRequestGuard()
const colorRequestGuard = createLatestRequestGuard()
const rejectRequestGuard = createLatestRequestGuard()
const withdrawRequestGuard = createLatestRequestGuard()
const claimRequestGuard = createLatestRequestGuard()
const reassignRequestGuard = createLatestRequestGuard()
const deleteReportRequestGuard = createLatestRequestGuard()
const suspendResumeRequestGuard = createLatestRequestGuard()
const executorsRequestGuard = createLatestRequestGuard()
const expertsRequestGuard = createLatestRequestGuard()
const actionRequestGuard = createLatestRequestGuard()
const downloadRequestGuard = createLatestRequestGuard()
const previewRequestGuard = createLatestRequestGuard()
const departmentRequestGuard = createLatestRequestGuard()
const confirmDialog = createConfirmDialog()
const hasRequestContent = computed(() => Boolean(selected.value?.product))
const feed = computed(() => newestFirstFeed(selected.value?.history || [], selected.value?.comments || []))
const documentGroups = computed(() => {
  const documents = selected.value?.documents || []
  return [
    { key: 'attachment', label: 'Сопроводительные документы', items: documents.filter(document => !['report', 'opinion'].includes(document.documentType)) },
    { key: 'report', label: 'Отчётные документы', items: documents.filter(document => document.documentType === 'report') },
    { key: 'opinion', label: 'Экспертное заключение', items: documents.filter(document => document.documentType === 'opinion') },
  ].filter(group => group.items.length)
})
const participants = computed(() => {
  if (!selected.value) return []
  return [
    { name: selected.value.initiator, role: 'Инициатор', roleCode: 'employee' },
    selected.value.executorId ? { name: selected.value.executor, role: 'Исполнитель ИЦ', roleCode: 'ic_executor' } : null,
    selected.value.expertId ? { name: selected.value.expert, role: 'Эксперт', roleCode: 'expert' } : null,
  ].filter(Boolean)
})

function avatarClassForAuthor(author) {
  if (author && author === selected.value?.expert) return avatarRoleClass('expert')
  if (author && author === selected.value?.executor) return avatarRoleClass('ic_executor')
  return avatarRoleClass('employee')
}

function eventIcon(action) {
  return {
    create: 'plus', import: 'download', assign_executor: 'user', claim_expert: 'user', reassign_expert: 'user',
    start: 'play', suspend: 'pause', resume: 'play', upload_report: 'upload', delete_report: 'trash',
    publish_opinion: 'file-check', security_approve: 'shield-check', security_return: 'return',
    reject: 'close', withdraw: 'close', change_department: 'building',
  }[action] || 'history'
}

function eventIconTone(action) {
  if (['security_approve', 'start', 'resume'].includes(action)) return 'positive'
  if (['delete_report', 'reject', 'withdraw'].includes(action)) return 'critical'
  if (['suspend', 'security_return'].includes(action)) return 'warning'
  if (['upload_report', 'publish_opinion'].includes(action)) return 'document'
  return 'neutral'
}

const COLOR_LABELS = { white: 'Без цвета', red: 'Красный', orange: 'Оранжевый', blue: 'Синий', violet: 'Фиолетовый', green: 'Зелёный' }

function colorLabel(color) {
  return COLOR_LABELS[color] || color
}
const processSteps = computed(() => {
  const labels = ['Зарегистрирована', 'В работе', 'Экспертиза', 'Контроль СБ', 'Завершена']
  const statusIndex = {
    'Заявка зарегистрирована': 0,
    'Заявка в работе': 1,
    'Работы приостановлены': 1,
    'Подготовка заключения': 2,
    'Контроль СБ': 3,
    'Заявка выполнена': 4,
  }[selected.value?.status]
  const terminal = ['В проведении испытаний отказано', 'Заявка отозвана'].includes(selected.value?.status)
  return labels.map((label, index) => ({
    label,
    state: terminal ? (index === 0 ? 'done' : 'future') : index < statusIndex ? 'done' : index === statusIndex ? 'current' : 'future',
  }))
})
const canStartAction = computed(() => canStartNow(selected.value))
const startHint = computed(() => {
  if (!selected.value) return ''
  if (!selected.value.canStart) return 'Работа по заявке уже начата.'
  if (!selected.value.executorId) return 'Начать работу можно после назначения исполнителя.'
  return ''
})
const hasHeroAction = computed(() => Boolean(selected.value && (
  selected.value.canAssignExecutor || selected.value.canStart || selected.value.canUploadReport
  || selected.value.canClaimExpert || selected.value.canReassignExpert || selected.value.canPublishOpinion
  || selected.value.canSecurityDecide || selected.value.canReject || selected.value.canWithdraw || selected.value.canDeleteReport
  || selected.value.canSuspend || selected.value.canResume
)))
const actionPrompt = computed(() => {
  if (!selected.value) return ''
  if (selected.value.canSecurityDecide) return 'Проверьте заключение и примите решение'
  if (selected.value.canPublishOpinion) return 'Подготовьте экспертное заключение'
  if (selected.value.canReassignExpert) return 'Подготовьте заключение или передайте заявку эксперту'
  if (selected.value.canClaimExpert) return 'Возьмите заявку на экспертизу'
  if (selected.value.canUploadReport) return 'Завершите испытания и загрузите отчёт'
  if (selected.value.canDeleteReport) return 'Проверьте загруженный отчёт'
  if (selected.value.canResume) return 'Возобновите работы по заявке'
  if (selected.value.canStart) return selected.value.executorId
    ? 'Запустите работу по заявке'
    : 'Сначала назначьте исполнителя'
  if (selected.value.canAssignExecutor) return 'Выберите и назначьте исполнителя'
  if (selected.value.canSuspend) return 'Продолжите работу или приостановите её'
  if (selected.value.canWithdraw) return 'Заявка ожидает начала работ'
  if (selected.value.canReject) return 'Примите заявку в работу или откажите'
  return 'Выберите действие по заявке'
})
const actionHelp = computed(() => {
  if (!selected.value) return null
  if (selected.value.canSecurityDecide) return { href: '/help/security-review.html', label: 'Инструкция по контролю СБ' }
  if (selected.value.canPublishOpinion || selected.value.canClaimExpert || selected.value.canReassignExpert) return { href: '/help/expert-opinion.html', label: 'Инструкция по формированию заключения' }
  if (selected.value.canUploadReport) return { href: '/help/report.html', label: 'Инструкция по загрузке отчёта испытаний' }
  if (selected.value.canAssignExecutor || selected.value.canStart || selected.value.canSuspend || selected.value.canResume) return { href: '/help/assignment.html', label: 'Инструкция по назначению и началу работы' }
  return null
})

function openAuditDrawer() {
  showAuditDrawer.value = true
  nextTick(() => auditDrawer.value?.querySelector('button')?.focus())
}

function closeAuditDrawer() {
  showAuditDrawer.value = false
  nextTick(() => auditTrigger.value?.focus())
}

function openHelpDrawer() {
  showHelpDrawer.value = true
  nextTick(() => helpDrawer.value?.querySelector('button')?.focus())
}

function closeHelpDrawer() {
  showHelpDrawer.value = false
  nextTick(() => helpTrigger.value?.focus())
}

function handleHelpKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeHelpDrawer()
    return
  }
  if (event.key !== 'Tab' || !helpDrawer.value) return
  const focusable = [...helpDrawer.value.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')]
    .filter(element => !element.disabled)
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function handleAuditKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeAuditDrawer()
    return
  }
  if (event.key !== 'Tab' || !auditDrawer.value) return
  const focusable = [...auditDrawer.value.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')]
    .filter(element => !element.disabled)
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function fileExtensionFor(document) {
  const fileName = document.originalName || document.title || ''
  const extension = fileName.includes('.') ? fileName.split('.').at(-1).toUpperCase() : ''
  return /^[A-Z0-9]{1,5}$/.test(extension) ? extension : documentKind(document.mimeType).label
}

function fileTypeClassFor(document) {
  const extension = fileExtensionFor(document).toLowerCase()
  if (extension === 'pdf') return 'pdf'
  if (['xlsx', 'xls', 'csv'].includes(extension)) return 'xlsx'
  if (['docx', 'doc', 'rtf'].includes(extension)) return 'docx'
  if (['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(extension)) return 'image'
  return documentKind(document.mimeType).className
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
    emit('loaded', selected.value)
    executorChoice.value = selected.value.executorId || ''
    expertChoice.value = ''
    if (selected.value.canAssignExecutor && !executors.value.length) loadExecutors()
    if (selected.value.canReassignExpert && !experts.value.length) loadExperts()
  } catch (error) {
    if (!detailRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    detailError.value = error.status === 404
      ? 'Заявка не найдена или недоступна.'
      : 'Не удалось загрузить актуальные данные заявки.'
  } finally {
    if (detailRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      detailLoading.value = false
    }
  }
}

function openDepartmentModal() {
  departmentDraft.value = selected.value.department === 'Подразделение не указано' ? '' : selected.value.department
  departmentError.value = ''
  showDepartmentModal.value = true
}

async function changeDepartment() {
  const department = departmentDraft.value.trim()
  if (!department) return
  const requestId = selected.value.backendId
  const token = departmentRequestGuard.begin(requestId)
  departmentLoading.value = true
  departmentError.value = ''
  try {
    await requestApi.changeDepartment(requestId, department, selected.value.lockVersion)
    if (!departmentRequestGuard.isCurrent(token, selected.value?.backendId)) return
    showDepartmentModal.value = false
    await loadRequestDetails(selected.value)
    emit('updated')
  } catch (error) {
    if (!departmentRequestGuard.isCurrent(token, selected.value?.backendId)) return
    if (error.status === 409) {
      showDepartmentModal.value = false
      await recoverConflict(requestId, 'Заявка уже изменена.')
      return
    }
    departmentError.value = error.payload?.errors?.department?.[0] || error.message || 'Не удалось изменить подразделение.'
  } finally {
    if (departmentRequestGuard.isCurrent(token, selected.value?.backendId)) departmentLoading.value = false
  }
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
    emit('updated')
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
          ? 'На текущем этапе загружать документы нельзя.'
          : 'Не удалось загрузить документ.'
  } finally {
    if (documentRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      documentLoading.value = false
    }
  }
}

async function downloadDocument(document) {
  const requestId = selected.value.backendId
  const token = downloadRequestGuard.begin(requestId)
  documentError.value = ''
  try {
    const blob = await requestApi.downloadDocument(document.versionId)
    if (!downloadRequestGuard.isCurrent(token, selected.value?.backendId)) return
    triggerBlobDownload(blob, document.originalName)
  } catch {
    if (downloadRequestGuard.isCurrent(token, selected.value?.backendId)) documentError.value = 'Не удалось скачать документ.'
  }
}

async function openDocument(document) {
  const requestId = selected.value.backendId
  const previewWindow = window.open('', '_blank')
  if (!previewWindow) {
    documentError.value = 'Браузер заблокировал новую вкладку. Разрешите всплывающие окна или скачайте документ.'
    return
  }
  previewWindow.opener = null
  const token = previewRequestGuard.begin(requestId)
  documentError.value = ''
  try {
    const blob = await requestApi.downloadDocument(document.versionId)
    if (!previewRequestGuard.isCurrent(token, selected.value?.backendId)) {
      previewWindow.close()
      return
    }
    const url = URL.createObjectURL(blob)
    previewWindow.location.replace(url)
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch {
    previewWindow.close()
    if (previewRequestGuard.isCurrent(token, selected.value?.backendId)) documentError.value = 'Не удалось открыть документ. Попробуйте скачать файл.'
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
      commentError.value = 'Введите комментарий длиной не более 10 000 символов.'
    } else if (error.status === 409) {
      await loadRequestDetails(selected.value)
      commentError.value = 'На текущем этапе добавлять комментарии нельзя.'
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

async function loadExecutors() {
  const requestId = selected.value?.backendId
  const requestToken = executorsRequestGuard.begin(requestId)
  try {
    const result = await requestApi.executors()
    if (!executorsRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    executors.value = result.items
  } catch {
    if (executorsRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      actionError.value = 'Не удалось загрузить список исполнителей.'
    }
  }
}

async function loadExperts() {
  const requestId = selected.value?.backendId
  const requestToken = expertsRequestGuard.begin(requestId)
  try {
    const result = await requestApi.experts()
    if (!expertsRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    experts.value = result.items
  } catch {
    if (expertsRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      actionError.value = 'Не удалось загрузить список экспертов.'
    }
  }
}

async function refreshSelected(requestId) {
  if (selected.value?.backendId === requestId) {
    selected.value = withoutStaleActions(selected.value)
  }
  if (selected.value?.backendId !== requestId) return
  await loadRequestDetails(selected.value)
}

async function recoverConflict(requestId, message) {
  try {
    await refreshSelected(requestId)
    actionError.value = `${message} Данные обновлены — проверьте актуальный статус.`
  } catch {
    actionError.value = `${message} Не удалось обновить данные. Обновите страницу перед следующим действием.`
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
      colorMenu.value?.removeAttribute('open')
    } catch {
      if (!colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      colorError.value = 'Цвет сохранён, но данные на экране не обновились.'
    }
  } catch (error) {
    if (!colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      colorError.value = error.status === 403
        ? 'У вас нет права менять цвет заявки.'
        : 'Не удалось сохранить цвет. Повторите попытку.'
    }
  } finally {
    if (colorRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      colorLoading.value = false
    }
  }
}

async function rejectRequest() {
  if (rejectLoading.value) return
  const requestId = selected.value.backendId
  const lockVersion = selected.value.lockVersion
  const confirmed = await confirmDialog.ask('Отказать в проведении испытаний?', {
    confirmLabel: 'Отказать',
    danger: true,
    reasonField: { required: true, placeholder: 'Опишите причину отказа' },
  })
  if (!confirmed) return
  if (selected.value?.backendId !== requestId) return
  const requestToken = rejectRequestGuard.begin(requestId)
  rejectLoading.value = true
  rejectError.value = ''
  try {
    await requestApi.reject(requestId, lockVersion, confirmed.reason)
    if (!rejectRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!rejectRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      rejectError.value = 'Отказ оформлен, но данные на экране не обновились.'
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
  const requestId = selected.value.backendId
  const lockVersion = selected.value.lockVersion
  const confirmed = await confirmDialog.ask('Отозвать эту заявку?', {
    confirmLabel: 'Отозвать',
    danger: true,
    reasonField: { required: true, placeholder: 'Опишите причину отзыва' },
  })
  if (!confirmed) return
  if (selected.value?.backendId !== requestId) return
  const requestToken = withdrawRequestGuard.begin(requestId)
  withdrawLoading.value = true
  withdrawError.value = ''
  try {
    await requestApi.withdraw(requestId, lockVersion, confirmed.reason)
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
  const requestId = selected.value.backendId
  const lockVersion = selected.value.lockVersion
  if (!(await confirmDialog.ask('Назначить выбранного сотрудника исполнителем?', { confirmLabel: 'Назначить' }))) return
  if (selected.value?.backendId !== requestId) return

  const requestToken = actionRequestGuard.begin(requestId)
  actionLoading.value = true
  actionError.value = ''
  try {
    await requestApi.assignExecutor(requestId, Number(executorChoice.value), lockVersion)
    if (!actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) actionError.value = 'Исполнитель назначен, но данные на экране не обновились. Обновите страницу перед следующим действием.'
    }
  } catch (error) {
    if (!actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      actionError.value = error.status === 403
        ? 'У вас нет права назначать исполнителя.'
        : 'Не удалось назначить исполнителя. Обновите страницу и повторите попытку.'
    }
  } finally {
    if (actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) actionLoading.value = false
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
      claimError.value = 'Заявка принята в работу, но данные на экране не обновились. Обновите страницу перед следующим действием.'
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
  if (!(await confirmDialog.ask('Передать заявку выбранному эксперту?', { confirmLabel: 'Передать' }))) return

  const requestId = selected.value.backendId
  const requestToken = reassignRequestGuard.begin(requestId)
  reassignLoading.value = true
  reassignError.value = ''
  try {
    await requestApi.reassignExpert(requestId, Number(expertChoice.value), selected.value.lockVersion)
    if (!reassignRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    expertChoice.value = ''
    try {
      await refreshSelected(requestId)
    } catch {
      if (!reassignRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      reassignError.value = 'Заявка переназначена, но данные на экране не обновились. Обновите страницу перед следующим действием.'
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
  const context = await confirmRequestAction(
    () => selected.value,
    () => confirmDialog.ask('Удалить загруженный отчёт испытаний? Отчёт и заключение по нему станут недоступны.', {
      confirmLabel: 'Удалить',
      danger: true,
      reasonField: { required: true, placeholder: 'Опишите причину удаления отчёта' },
    }),
  )
  if (!context) return
  const { requestId, lockVersion, confirmed } = context
  const requestToken = deleteReportRequestGuard.begin(requestId)
  deleteReportLoading.value = true
  deleteReportError.value = ''
  try {
    await requestApi.deleteReport(requestId, lockVersion, confirmed.reason)
    if (!deleteReportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!deleteReportRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      deleteReportError.value = 'Отчёт удалён, но данные на экране не обновились. Обновите страницу перед следующим действием.'
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

function openOpinionModal() {
  opinionError.value = ''
  showOpinionModal.value = true
}

// Отдельная кнопка внутри модалки, а не через confirmDialog: открыть
// модалку → заполнить реальный текст заключения → нажать «Опубликовать и
// передать в СБ» — уже полноценное подтверждающее действие, второй общий
// confirmDialog поверх модалки был бы избыточным повторным подтверждением
// (issue #153).
async function publishOpinion() {
  const body = opinionDraft.value.trim()
  if (body.length < 10) {
    opinionError.value = 'Заключение должно содержать не менее 10 символов.'
    return
  }

  const requestId = selected.value.backendId
  const requestToken = opinionRequestGuard.begin(requestId)
  opinionLoading.value = true
  opinionError.value = ''
  try {
    await requestApi.publishOpinion(requestId, body, selected.value.lockVersion)
    if (!opinionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    selected.value = { ...selected.value, canPublishOpinion: false }
    opinionDraft.value = ''
    showOpinionModal.value = false
    try {
      await refreshSelected(requestId)
    } catch {
      actionError.value = 'Заключение опубликовано, но обновить карточку не удалось. Не отправляйте его повторно.'
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
  const isApprove = decision === 'approve'
  const confirmed = await confirmDialog.ask(
    isApprove ? 'Согласовать заключение и завершить заявку?' : 'Вернуть заявку исполнителю на доработку?',
    isApprove
      ? { confirmLabel: 'Согласовать' }
      : {
        confirmLabel: 'Вернуть',
        reasonField: { required: true, placeholder: 'Опишите, что нужно исправить' },
      },
  )
  if (!confirmed) return
  const reason = isApprove ? null : confirmed.reason

  const requestId = selected.value.backendId
  const requestToken = securityRequestGuard.begin(requestId)
  securityLoading.value = true
  securityError.value = ''
  try {
    await requestApi.decideSecurity(requestId, decision, reason || null, selected.value.lockVersion)
    if (!securityRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    selected.value = { ...selected.value, canSecurityDecide: false }
    try {
      await refreshSelected(requestId)
    } catch {
      actionError.value = 'Решение сохранено, но данные на экране не обновились. Обновите страницу перед следующим действием.'
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
  const requestId = selected.value.backendId
  const lockVersion = selected.value.lockVersion
  if (!(await confirmDialog.ask('Начать работу по заявке?', { confirmLabel: 'Начать работу' }))) return
  if (selected.value?.backendId !== requestId) return

  const requestToken = actionRequestGuard.begin(requestId)
  actionLoading.value = true
  actionError.value = ''
  try {
    await requestApi.start(requestId, lockVersion)
    if (!actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    startHintRevealed.value = false
    try {
      await refreshSelected(requestId)
    } catch {
      if (actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) actionError.value = 'Заявка переведена в работу, но данные на экране не обновились. Обновите страницу перед следующим действием.'
    }
  } catch (error) {
    if (!actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      actionError.value = error.status === 403
        ? 'У вас нет права переводить эту заявку в работу.'
        : 'Не удалось перевести заявку в работу. Повторите попытку.'
    }
  } finally {
    if (actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) actionLoading.value = false
  }
}

// aria-disabled, а не нативный disabled: клик должен ловиться и в
// неактивном состоянии, чтобы раскрыть подсказку под строкой (issue #153).
function handleStartClick() {
  if (!canStartAction.value) {
    startHintRevealed.value = true
    return
  }
  startRequest()
}

async function suspendOrResumeRequest(action) {
  if (suspendResumeLoading.value) return
  const isSuspend = action === 'suspend'
  const context = await confirmRequestAction(
    () => selected.value,
    () => confirmDialog.ask(
      isSuspend ? 'Приостановить работу по заявке?' : 'Возобновить работу по заявке?',
      {
        confirmLabel: isSuspend ? 'Приостановить' : 'Возобновить',
        reasonField: isSuspend ? { required: true, placeholder: 'Опишите причину приостановки' } : null,
      },
    ),
  )
  if (!context) return

  const { requestId, lockVersion, confirmed } = context
  const requestToken = suspendResumeRequestGuard.begin(requestId)
  suspendResumeLoading.value = true
  suspendResumeError.value = ''
  try {
    await (isSuspend ? requestApi.suspend(requestId, lockVersion, confirmed.reason) : requestApi.resume(requestId, lockVersion))
    if (!suspendResumeRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!suspendResumeRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      suspendResumeError.value = isSuspend
        ? 'Работа приостановлена, но данные на экране не обновились. Обновите страницу перед следующим действием.'
        : 'Работа возобновлена, но данные на экране не обновились. Обновите страницу перед следующим действием.'
    }
  } catch (error) {
    if (!suspendResumeRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    if (error.status === 409) {
      await recoverConflict(requestId, 'Заявка уже изменена.')
    } else {
      suspendResumeError.value = error.status === 403
        ? 'Приостановить или возобновить работу может только назначенный исполнитель или руководитель.'
        : isSuspend
          ? 'Не удалось приостановить работу. Повторите попытку.'
          : 'Не удалось возобновить работу. Повторите попытку.'
    }
  } finally {
    if (suspendResumeRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      suspendResumeLoading.value = false
    }
  }
}


function invalidateRequests() {
  for (const guard of [detailRequestGuard, commentRequestGuard, commentsPageRequestGuard, documentRequestGuard, reportRequestGuard, opinionRequestGuard, securityRequestGuard, colorRequestGuard, rejectRequestGuard, withdrawRequestGuard, claimRequestGuard, reassignRequestGuard, deleteReportRequestGuard, suspendResumeRequestGuard, executorsRequestGuard, expertsRequestGuard, actionRequestGuard, downloadRequestGuard, previewRequestGuard, departmentRequestGuard]) guard.invalidate()
}

function resetRequestLocalState() {
  confirmDialog.cancel()
  commentDraft.value = ''
  opinionDraft.value = ''
  showOpinionModal.value = false
  showHelpDrawer.value = false
  departmentDraft.value = ''
  showDepartmentModal.value = false
  startHintRevealed.value = false
  executorChoice.value = ''
  expertChoice.value = ''

  for (const error of [
    detailError, commentError, documentError, reportError, opinionError,
    securityError, colorError, rejectError, withdrawError, claimError,
    reassignError, deleteReportError, suspendResumeError, departmentError,
  ]) error.value = ''

  for (const loading of [
    actionLoading, detailLoading, commentLoading, olderCommentsLoading,
    documentLoading, reportLoading, opinionLoading, securityLoading,
    colorLoading, rejectLoading, withdrawLoading, claimLoading,
    reassignLoading, deleteReportLoading, suspendResumeLoading, departmentLoading,
  ]) loading.value = false
}

watch(() => props.requestId, requestId => {
  invalidateRequests()
  resetRequestLocalState()
  selected.value = { backendId: requestId }
  actionError.value = props.initialWarning
  loadRequestDetails(selected.value)
}, { immediate: true })

onBeforeUnmount(() => {
  invalidateRequests()
  confirmDialog.cancel()
})
</script>
<template>
  <section class="page request-page screen-panel" :class="{ 'screen-panel--active': hasRequestContent, 'request-details-shlz-demo shlz-scope': isShlzMode }">
    <p v-if="detailLoading" class="detail-state">Загрузка данных заявки…</p>
    <p v-if="detailError" class="detail-state error">{{ detailError }}</p>
    <article v-if="hasRequestContent && !detailError" class="card object-band request-entity-head" :class="{ 'shlz-surface': isShlzMode }">
      <button type="button" class="request-corner-back" :class="`request-corner-${selected.color}`" aria-label="Вернуться к списку заявок" @click="emit('close')">
        <svg class="request-corner-shape" viewBox="0 0 52 52" aria-hidden="true">
          <path d="M15 1h31.5c4 0 5.9 4.8 3.1 7.6l-41 41C5.8 52.4 1 50.5 1 46.5V15C1 7.3 7.3 1 15 1Z" />
          <path class="request-corner-edge" d="M49.6 8.6 8.6 49.6" />
        </svg>
        <AppIcon class="request-corner-arrow" name="arrow-left" :size="16" />
      </button>
      <div class="object-status-row">
        <span :class="isShlzMode ? ['shlz-status', shlzStatusToneClass(selected.tone)] : ['badge', selected.tone]">{{ selected.status }}</span>
        <details v-if="selected.canSetColor" ref="colorMenu" class="request-color-control">
          <summary><span class="request-color-dot" :class="selected.color" aria-hidden="true"></span>Цвет</summary>
          <div class="request-color-menu" role="group" aria-label="Цвет заявки в реестре">
            <button v-for="color in REQUEST_COLORS" :key="color" type="button" :class="{ active: selected.color === color }" :disabled="colorLoading" @click="setColorMark(color)"><span>{{ colorLabel(color) }}</span><span class="request-color-dot" :class="color" aria-hidden="true"></span></button>
          </div>
        </details>
      </div>
      <p v-if="colorError" class="action-error">{{ colorError }}</p>
      <h2 class="object-title">{{ selected.product }}</h2>
      <p class="request-entity-context">{{ selected.manufacturer || 'Производитель не указан' }} · {{ selected.sampleQuantity || '—' }} шт.</p>
      <section id="request-overview" class="request-overview" aria-labelledby="overview-title">
        <h3 id="overview-title" class="visually-hidden">Ключевые сведения</h3>
        <div class="facts-row">
          <div class="fact"><span>Подразделение</span><b>{{ selected.department }}</b><button v-if="selected.canEditDepartment" type="button" class="secondary" @click="openDepartmentModal">Изменить</button></div>
          <div class="fact"><span>Производитель</span><b>{{ selected.manufacturer || '—' }}</b></div>
          <div class="fact"><span>Поставщик</span><b>{{ selected.supplier }}</b></div>
          <div class="fact"><span>Количество образцов</span><b>{{ selected.sampleQuantity || '—' }} шт.</b></div>
        </div>
        <div class="method-row"><span>Метод испытаний</span><p>{{ selected.testMethod || '—' }}</p></div>
      </section>
    </article>
    <div v-if="hasRequestContent && !detailError" class="request-grid">
      <div class="stack">
        <section class="card process-section request-process" :class="{ 'shlz-surface': isShlzMode }" aria-labelledby="process-title">
          <div class="section-title"><h3 id="process-title">Процесс заявки</h3><button ref="auditTrigger" type="button" class="request-text-button" @click="openAuditDrawer">Подробная история</button></div>
          <ol class="process-timeline">
            <li v-for="step in processSteps" :key="step.label" :class="step.state"><span class="process-node" aria-hidden="true"></span><b>{{ step.label }}</b><small>{{ step.state === 'current' ? 'Текущий этап' : step.state === 'done' ? 'Завершено' : 'Ожидается' }}</small></li>
          </ol>
          <div v-if="hasHeroAction || actionError" class="request-process-action">
            <div class="request-process-action-main">
              <div class="request-action-context"><span>Следующий шаг</span><b>{{ actionPrompt }}</b></div>
              <div class="request-action-bar">
                <div v-if="selected.canAssignExecutor" class="request-action-group">
                  <select v-model="executorChoice" :class="{ 'shlz-select shlz-input--sm': isShlzMode }" :disabled="actionLoading" aria-label="Исполнитель ИЦ"><option value="">Выберите исполнителя</option><option v-for="executor in executors" :key="executor.id" :value="executor.id">{{ executor.displayName }}</option></select>
                  <button type="button" :class="isShlzMode ? ['shlz-button', 'shlz-button--sm', { 'shlz-button--primary': !selected.executorId }] : (selected.executorId ? 'secondary' : 'primary')" :disabled="actionLoading || !executorChoice" @click="assignExecutor">{{ actionLoading ? 'Сохранение…' : (selected.executorId ? 'Переназначить' : 'Назначить') }}</button>
                </div>
                <div v-if="selected.canStart || selected.canSuspend || selected.canResume" class="request-action-group">
                  <button v-if="selected.canStart" type="button" :class="isShlzMode ? ['shlz-button', 'shlz-button--sm', { 'shlz-button--primary': selected.executorId, 'is-disabled': !canStartAction }] : [selected.executorId ? 'primary' : 'secondary', { 'is-disabled': !canStartAction }]" :aria-disabled="!canStartAction" :disabled="actionLoading" @click="handleStartClick">{{ actionLoading ? 'Запуск…' : 'Начать работу' }}</button>
                  <button v-else-if="selected.canSuspend" type="button" :class="isShlzMode ? 'shlz-button shlz-button--sm' : 'secondary'" :disabled="suspendResumeLoading" @click="suspendOrResumeRequest('suspend')">{{ suspendResumeLoading ? 'Сохранение…' : 'Приостановить' }}</button>
                  <button v-else-if="selected.canResume" type="button" :class="isShlzMode ? 'shlz-button shlz-button--primary shlz-button--sm' : 'primary'" :disabled="suspendResumeLoading" @click="suspendOrResumeRequest('resume')">{{ suspendResumeLoading ? 'Сохранение…' : 'Возобновить' }}</button>
                </div>
                <div v-if="selected.canUploadReport || selected.canDeleteReport" class="request-action-group">
                  <label v-if="selected.canUploadReport" :class="isShlzMode ? 'shlz-button shlz-button--primary shlz-button--sm request-shlz-file-button' : 'primary upload-button'">{{ reportLoading ? 'Загрузка…' : 'Загрузить отчёт' }}<input type="file" :disabled="reportLoading" accept=".pdf,application/pdf" @change="uploadReport" /></label>
                  <button v-if="selected.canDeleteReport" type="button" :class="isShlzMode ? 'shlz-button shlz-button--sm request-shlz-danger-button' : 'secondary danger'" :disabled="deleteReportLoading" @click="deleteReport">{{ deleteReportLoading ? 'Удаление…' : 'Удалить отчёт' }}</button>
                </div>
                <div v-if="selected.canClaimExpert" class="request-action-group"><button type="button" :class="isShlzMode ? 'shlz-button shlz-button--primary shlz-button--sm' : 'primary'" :disabled="claimLoading" @click="claimExpert">{{ claimLoading ? 'Сохранение…' : 'Взять в работу' }}</button></div>
                <div v-if="selected.canPublishOpinion || selected.canReassignExpert" class="request-action-group">
                  <button v-if="selected.canPublishOpinion" type="button" :class="isShlzMode ? 'shlz-button shlz-button--primary shlz-button--sm' : 'primary'" :disabled="opinionLoading" @click="openOpinionModal">Написать заключение</button>
                  <select v-if="selected.canReassignExpert" v-model="expertChoice" :class="{ 'shlz-select shlz-input--sm': isShlzMode }" :disabled="reassignLoading" aria-label="Новый эксперт"><option value="">Выберите эксперта</option><option v-for="expert in experts.filter(candidate => candidate.id !== selected.expertId)" :key="expert.id" :value="expert.id">{{ expert.displayName }}</option></select>
                  <button v-if="selected.canReassignExpert" type="button" :class="isShlzMode ? 'shlz-button shlz-button--sm' : 'secondary'" :disabled="reassignLoading || !expertChoice" @click="reassignExpert">{{ reassignLoading ? 'Передача…' : 'Передать' }}</button>
                </div>
                <div v-if="selected.canSecurityDecide" class="request-action-group">
                  <button type="button" :class="isShlzMode ? 'shlz-button shlz-button--primary shlz-button--sm' : 'primary'" :disabled="securityLoading" @click="decideSecurity('approve')">{{ securityLoading ? 'Сохранение…' : 'Согласовать' }}</button>
                  <button type="button" :class="isShlzMode ? 'shlz-button shlz-button--sm' : 'secondary'" :disabled="securityLoading" @click="decideSecurity('return')">Вернуть в работу</button>
                </div>
                <div v-if="selected.canReject || selected.canWithdraw" class="request-action-group request-action-group--danger">
                  <button v-if="selected.canReject" type="button" :class="isShlzMode ? 'shlz-button shlz-button--sm request-shlz-danger-button' : 'request-danger-action'" :disabled="rejectLoading" @click="rejectRequest">{{ rejectLoading ? 'Сохранение…' : 'Отказать' }}</button>
                  <button v-if="selected.canWithdraw" type="button" :class="isShlzMode ? 'shlz-button shlz-button--sm request-shlz-danger-button' : 'request-danger-action'" :disabled="withdrawLoading" @click="withdrawRequest">{{ withdrawLoading ? 'Сохранение…' : 'Отозвать' }}</button>
                </div>
              </div>
              <button v-if="actionHelp" ref="helpTrigger" type="button" :class="isShlzMode ? 'request-help-action shlz-button shlz-button--text shlz-button--sm shlz-button--icon' : 'request-action-help'" :aria-label="actionHelp.label" :title="actionHelp.label" @click="openHelpDrawer"><AppIcon name="help" :size="isShlzMode ? 24 : 16" :shlz="isShlzMode" /></button>
            </div>
            <p v-if="selected.canStart && startHintRevealed && startHint" class="hero-hint">{{ startHint }}</p>
            <p v-for="(error, index) in [suspendResumeError, rejectError, reportError, deleteReportError, claimError, reassignError, securityError, withdrawError, actionError].filter(Boolean)" :key="`${index}-${error}`" class="action-error">{{ error }}</p>
          </div>
        </section>

        <article id="request-comments" class="card feed request-comments" :class="{ 'shlz-surface': isShlzMode }">
          <div class="section-title"><h3>Лента</h3></div>
          <p v-if="commentError" class="action-error">{{ commentError }}</p>
          <form v-if="canSubmitComment(selected, detailLoading)" class="comment-input request-comment-composer" :class="{ 'request-shlz-comment-composer': isShlzMode }" @submit.prevent="addComment"><span class="avatar small">{{ currentInitials }}</span><input v-model="commentDraft" :class="{ 'shlz-input shlz-input--sm': isShlzMode }" :disabled="commentLoading" maxlength="10000" placeholder="Оставьте комментарий…" /><button :class="{ 'shlz-button shlz-button--primary shlz-button--sm shlz-button--icon': isShlzMode }" :disabled="commentLoading" aria-label="Отправить комментарий"><AppIcon name="send" :size="16" :shlz="isShlzMode" /></button></form>
          <p v-else class="placeholder-copy request-comment-unavailable">На текущем этапе добавлять комментарии нельзя.</p>
          <div class="stream">
            <div v-for="entry in feed" :key="`${entry.type}-${entry.id}`" class="entry" :class="{ system: entry.type !== 'comment' }">
              <span v-if="entry.type === 'comment'" class="avatar small" :class="avatarClassForAuthor(entry.author)">{{ initialsFor(entry.author) }}</span>
              <span v-else class="request-feed-event-actor" aria-hidden="true"><span class="avatar small request-feed-system-avatar">{{ initialsFor(entry.actor) }}</span><span class="request-feed-event-mark" :class="eventIconTone(entry.action)"><AppIcon :name="eventIcon(entry.action)" :size="10" /></span></span>
              <div class="entry-body">
                <div class="entry-head"><b>{{ entry.type === 'comment' ? entry.author : entry.actor }}</b><time>{{ entry.type === 'comment' ? entry.createdAt : entry.occurredAt }}</time></div>
                <p>{{ entry.type === 'comment' ? entry.body : entry.description }}</p>
                <div v-if="entry.versionId && entry.originalName" class="request-audit-file request-feed-file"><button type="button" class="request-audit-file-open app-tooltip" data-tooltip="Открыть документ" :aria-label="`Открыть ${entry.originalName}`" @click="openDocument(entry)"><span class="request-file-thumb request-audit-file-thumb" aria-hidden="true"><span class="request-file-lines"></span><span class="request-file-type" :class="fileTypeClassFor(entry)">{{ fileExtensionFor(entry) }}</span></span><span><b :title="entry.originalName">{{ entry.originalName }}</b><small>Открыть вложение</small></span></button><button type="button" class="request-file-action app-tooltip" data-tooltip="Скачать документ" :aria-label="`Скачать ${entry.originalName}`" @click.stop="downloadDocument(entry)"><AppIcon name="download" :size="14" /></button></div>
              </div>
            </div>
          </div>
          <p v-if="!feed.length" class="placeholder-copy">Событий пока нет.</p>
          <button v-if="selected.commentsPage?.hasMore" class="secondary" :disabled="olderCommentsLoading" @click="loadOlderComments">{{ olderCommentsLoading ? 'Загрузка…' : 'Показать ранние комментарии' }}</button>
        </article>
      </div>
      <aside class="stack side-column">
        <article class="card request-participants" :class="{ 'shlz-surface': isShlzMode }"><h3>Участники</h3>
          <div v-for="person in participants" :key="`${person.role}-${person.name}`" class="request-person-row"><span class="avatar small" :class="avatarRoleClass(person.roleCode)">{{ initialsFor(person.name) }}</span><span><b>{{ person.name }}</b><small>{{ person.role }}</small></span></div>
          <section class="request-security-section" aria-labelledby="security-control-title"><h3 id="security-control-title">Контроль СБ</h3><div class="request-security-status"><span class="security-mark-icon" :class="selected.securityMarkDisplay?.className" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path :d="selected.securityMarkDisplay?.path" /></svg></span><span><b>{{ selected.securityMarkDisplay?.label }}</b><small>Статус проверки</small></span></div></section>
        </article>
        <article id="request-documents" class="card documents request-documents" :class="{ 'shlz-surface': isShlzMode }"><div class="section-title request-documents-head"><h3>Документы <span class="request-document-count" :aria-label="`Документов: ${selected.documents?.length || 0}`">{{ selected.documents?.length || 0 }}</span></h3><label v-if="selected.canUploadDocument" :class="isShlzMode ? 'shlz-button shlz-button--text shlz-button--xs request-shlz-file-button' : 'request-document-upload'"><AppIcon v-if="!documentLoading" name="plus" :size="14" :shlz="isShlzMode" />{{ documentLoading ? 'Загрузка…' : 'Добавить' }}<input type="file" :disabled="documentLoading" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" @change="uploadDocument" /></label></div>
          <section v-for="group in documentGroups" :key="group.key" class="request-document-group" :aria-labelledby="`document-group-${group.key}`"><h4 :id="`document-group-${group.key}`">{{ group.label }} <span>{{ group.items.length }}</span></h4><div v-for="document in group.items" :key="document.versionId" class="document-row request-file-card"><button type="button" class="request-file-open app-tooltip" data-tooltip="Открыть документ" :aria-label="`Открыть ${document.title}, версия ${document.version}`" @click="openDocument(document)"><span class="request-file-thumb" aria-hidden="true"><span class="request-file-lines"></span><span class="request-file-type" :class="fileTypeClassFor(document)">{{ fileExtensionFor(document) }}</span></span><span class="request-file-copy"><b :title="document.title">{{ document.title }}</b><small>Версия {{ document.version }} · {{ document.size }}</small><small>{{ document.createdAt }}</small></span></button><button type="button" class="request-file-action app-tooltip" data-tooltip="Скачать документ" :aria-label="`Скачать ${document.title}`" @click.stop="downloadDocument(document)"><AppIcon name="download" :size="14" /></button></div></section>
          <div v-if="isShlzMode && !selected.documents?.length" class="request-empty-state"><div class="shlz-empty-state shlz-empty-state--simple"><span class="shlz-empty-state__visual" aria-hidden="true"><AppIcon name="file" :size="32" :shlz="true" /></span><p class="shlz-empty-state__title">Документов пока нет.</p></div></div><p v-else-if="!selected.documents?.length" class="placeholder-copy">Документов пока нет.</p>
          <p v-if="documentError" class="action-error">{{ documentError }}</p>
        </article>
      </aside>
    </div>
  </section>
  <div v-if="showAuditDrawer" class="request-drawer-overlay" @click.self="closeAuditDrawer">
    <aside ref="auditDrawer" class="request-drawer" role="dialog" aria-modal="true" aria-labelledby="audit-title" @keydown="handleAuditKeydown">
      <header class="request-drawer-head"><div><p>Заявка №{{ selected.id }}</p><h2 id="audit-title">История процесса</h2></div><button type="button" aria-label="Закрыть историю" @click="closeAuditDrawer"><AppIcon name="close" /></button></header>
      <div class="request-drawer-body">
        <div v-for="entry in selected.history || []" :key="entry.id" class="request-audit-entry"><span class="request-audit-node" aria-hidden="true"></span><div><b>{{ entry.actor }}</b><p>{{ entry.description }}</p><time>{{ entry.occurredAt }}</time><div v-if="entry.versionId && entry.originalName" class="request-audit-file"><button type="button" class="request-audit-file-open app-tooltip" data-tooltip="Открыть документ" :aria-label="`Открыть ${entry.originalName}`" @click="openDocument(entry)"><span class="request-file-thumb request-audit-file-thumb" aria-hidden="true"><span class="request-file-lines"></span><span class="request-file-type" :class="fileTypeClassFor(entry)">{{ fileExtensionFor(entry) }}</span></span><span><b :title="entry.originalName">{{ entry.originalName }}</b><small>Открыть вложение</small></span></button><button type="button" class="request-file-action app-tooltip" data-tooltip="Скачать документ" :aria-label="`Скачать ${entry.originalName}`" @click.stop="downloadDocument(entry)"><AppIcon name="download" :size="14" /></button></div></div></div>
        <p v-if="!selected.history?.length" class="placeholder-copy">История процесса пока пуста.</p>
      </div>
    </aside>
  </div>
  <div v-if="showHelpDrawer && actionHelp" class="request-drawer-overlay" @click.self="closeHelpDrawer">
    <aside ref="helpDrawer" class="request-drawer request-help-drawer" role="dialog" aria-modal="true" aria-labelledby="help-title" @keydown="handleHelpKeydown">
      <header class="request-drawer-head"><div><p>Заявка №{{ selected.id }}</p><h2 id="help-title">Справка</h2></div><button type="button" aria-label="Закрыть справку" @click="closeHelpDrawer"><AppIcon name="close" /></button></header>
      <HelpArticle :src="actionHelp.href" />
    </aside>
  </div>
  <AppModal :open="confirmDialog.state.open" title="Подтвердите действие" title-id="request-confirm-title" description-id="request-confirm-message" size="small" alert @close="confirmDialog.cancel">
    <p id="request-confirm-message">{{ confirmDialog.state.message }}</p>
    <label v-if="confirmDialog.state.reasonField" class="confirm-reason-field">
      <span class="visually-hidden">Причина действия</span>
      <textarea
        v-model="confirmDialog.state.reasonValue"
        maxlength="5000"
        :placeholder="confirmDialog.state.reasonField.placeholder"
      ></textarea>
    </label>
    <template #footer>
      <button type="button" class="secondary" @click="confirmDialog.cancel">Отмена</button>
      <button
        type="button"
        class="primary"
        :class="{ danger: confirmDialog.state.danger }"
        :disabled="confirmDialog.state.reasonField?.required && !confirmDialog.state.reasonValue.trim()"
        @click="confirmDialog.accept"
      >{{ confirmDialog.state.confirmLabel }}</button>
    </template>
  </AppModal>

  <AppModal :open="showOpinionModal" as="form" title="Экспертное заключение" title-id="opinion-modal-title" size="large" :busy="opinionLoading" @close="showOpinionModal = false" @submit="publishOpinion">
    <div class="fact-list opinion-summary">
      <div class="fact"><span>Объект испытаний</span><b>{{ selected.product }}</b></div>
      <div class="fact"><span>Производитель</span><b>{{ selected.manufacturer || '—' }}</b></div>
      <div class="fact"><span>Поставщик</span><b>{{ selected.supplier }}</b></div>
      <div class="fact"><span>Количество образцов</span><b>{{ selected.sampleQuantity || '—' }} шт.</b></div>
      <div class="fact wide"><span>Метод испытаний</span><b>{{ selected.testMethod || '—' }}</b></div>
    </div>
    <textarea v-model="opinionDraft" :disabled="opinionLoading" minlength="10" maxlength="20000" placeholder="Введите итоговое заключение по результатам испытаний"></textarea>
    <p v-if="opinionError" class="action-error">{{ opinionError }}</p>
    <template #footer>
      <button type="button" class="secondary" :disabled="opinionLoading" @click="showOpinionModal = false">Отмена</button>
      <button class="primary" :disabled="opinionLoading">{{ opinionLoading ? 'Публикация…' : 'Опубликовать и передать в СБ' }}</button>
    </template>
  </AppModal>
  <AppModal :open="showDepartmentModal" as="form" title="Изменить подразделение" title-id="department-modal-title" size="medium" :busy="departmentLoading" @close="showDepartmentModal = false" @submit="changeDepartment">
    <label>Подразделение<input v-model.trim="departmentDraft" :disabled="departmentLoading" maxlength="255" required /></label>
    <p class="placeholder-copy">Новое подразделение будет указано только в этой заявке. Изменение появится в журнале действий.</p>
    <p v-if="departmentError" class="action-error">{{ departmentError }}</p>
    <template #footer>
      <button type="button" class="secondary" :disabled="departmentLoading" @click="showDepartmentModal = false">Отмена</button>
      <button class="primary" :disabled="departmentLoading || !departmentDraft.trim()">{{ departmentLoading ? 'Сохранение…' : 'Сохранить' }}</button>
    </template>
  </AppModal>
</template>
