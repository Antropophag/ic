<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import { createConfirmDialog } from '../confirmDialog'
import { triggerBlobDownload } from '../download'
import { createLatestRequestGuard } from '../latestRequestGuard'
import { REQUEST_COLORS, canStartNow, canSubmitComment, commentFromApi, documentFromApi, documentKind, fromApi, historyFromApi, newestFirstFeed, withoutStaleActions } from '../registry'

const props = defineProps({ requestId: { type: Number, required: true }, currentInitials: { type: String, default: '' }, initialWarning: { type: String, default: '' } })
const emit = defineEmits(['loaded', 'unavailable', 'updated'])
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
const confirmDialog = createConfirmDialog()
const feed = computed(() => newestFirstFeed(selected.value?.history || [], selected.value?.comments || []))
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
      : 'Не удалось загрузить актуальную карточку заявки.'
  } finally {
    if (detailRequestGuard.isCurrent(requestToken, selected.value?.backendId)) {
      detailLoading.value = false
    }
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
          ? 'На текущем этапе загрузка документов запрещена.'
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
  const requestId = selected.value.backendId
  const lockVersion = selected.value.lockVersion
  const confirmed = await confirmDialog.ask('Отказать в проведении испытаний по этой заявке?', {
    confirmLabel: 'Отказать',
    danger: true,
    reasonField: { required: false, placeholder: 'Например, образец не соответствует требованиям к отбору' },
  })
  if (!confirmed) return
  if (selected.value?.backendId !== requestId) return
  const requestToken = rejectRequestGuard.begin(requestId)
  rejectLoading.value = true
  rejectError.value = ''
  try {
    await requestApi.reject(requestId, lockVersion, confirmed.reason || undefined)
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
  const requestId = selected.value.backendId
  const lockVersion = selected.value.lockVersion
  const confirmed = await confirmDialog.ask('Отозвать эту заявку?', {
    confirmLabel: 'Отозвать',
    danger: true,
    reasonField: { required: false, placeholder: 'Например, заявка подана повторно с уточнёнными данными' },
  })
  if (!confirmed) return
  if (selected.value?.backendId !== requestId) return
  const requestToken = withdrawRequestGuard.begin(requestId)
  withdrawLoading.value = true
  withdrawError.value = ''
  try {
    await requestApi.withdraw(requestId, lockVersion, confirmed.reason || undefined)
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
  if (!(await confirmDialog.ask('Назначить выбранного исполнителя на заявку?', { confirmLabel: 'Назначить' }))) return
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
      if (actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) actionError.value = 'Исполнитель назначен, но обновить карточку не удалось. Устаревшие действия отключены.'
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
    expertChoice.value = ''
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
    isApprove ? 'Согласовать заключение и завершить заявку?' : 'Вернуть заявку исполнителю с указанной причиной?',
    isApprove
      ? { confirmLabel: 'Согласовать', confirm: true }
      : {
        confirmLabel: 'Вернуть',
        reasonField: { required: true, placeholder: 'Например, требуется уточнить формулировку вывода' },
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
      actionError.value = 'Решение сохранено, но обновить карточку не удалось. Устаревшие действия отключены.'
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
  if (!(await confirmDialog.ask('Перевести заявку в работу?', { confirmLabel: 'Начать работу' }))) return
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
      if (actionRequestGuard.isCurrent(requestToken, selected.value?.backendId)) actionError.value = 'Заявка переведена в работу, но обновить карточку не удалось. Устаревшие действия отключены.'
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
  const confirmed = await confirmDialog.ask(
    isSuspend ? 'Приостановить работу по заявке?' : 'Возобновить работу по заявке?',
    { confirmLabel: isSuspend ? 'Приостановить' : 'Возобновить' },
  )
  if (!confirmed) return

  const requestId = selected.value.backendId
  const requestToken = suspendResumeRequestGuard.begin(requestId)
  suspendResumeLoading.value = true
  suspendResumeError.value = ''
  try {
    await (isSuspend ? requestApi.suspend(requestId, selected.value.lockVersion) : requestApi.resume(requestId, selected.value.lockVersion))
    if (!suspendResumeRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
    try {
      await refreshSelected(requestId)
    } catch {
      if (!suspendResumeRequestGuard.isCurrent(requestToken, selected.value?.backendId)) return
      suspendResumeError.value = isSuspend
        ? 'Работа приостановлена, но обновить карточку не удалось. Устаревшие действия отключены.'
        : 'Работа возобновлена, но обновить карточку не удалось. Устаревшие действия отключены.'
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
  for (const guard of [detailRequestGuard, commentRequestGuard, commentsPageRequestGuard, documentRequestGuard, reportRequestGuard, opinionRequestGuard, securityRequestGuard, colorRequestGuard, rejectRequestGuard, withdrawRequestGuard, claimRequestGuard, reassignRequestGuard, deleteReportRequestGuard, suspendResumeRequestGuard, executorsRequestGuard, expertsRequestGuard, actionRequestGuard, downloadRequestGuard]) guard.invalidate()
}

function resetRequestLocalState() {
  confirmDialog.cancel()
  commentDraft.value = ''
  opinionDraft.value = ''
  showOpinionModal.value = false
  startHintRevealed.value = false
  executorChoice.value = ''
  expertChoice.value = ''

  for (const error of [
    detailError, commentError, documentError, reportError, opinionError,
    securityError, colorError, rejectError, withdrawError, claimError,
    reassignError, deleteReportError, suspendResumeError,
  ]) error.value = ''

  for (const loading of [
    actionLoading, detailLoading, commentLoading, olderCommentsLoading,
    documentLoading, reportLoading, opinionLoading, securityLoading,
    colorLoading, rejectLoading, withdrawLoading, claimLoading,
    reassignLoading, deleteReportLoading, suspendResumeLoading,
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
  <section class="page request-page">
    <p v-if="detailLoading" class="detail-state">Загрузка актуальной карточки…</p>
    <p v-if="detailError" class="detail-state error">{{ detailError }}</p>
    <article class="card object-band">
      <div class="object-status-row">
        <span class="badge" :class="selected.tone">{{ selected.status }}</span>
        <div v-if="selected.canSetColor" class="color-picker inline">
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
      </div>
      <p v-if="colorError" class="action-error">{{ colorError }}</p>
      <h4 class="object-title">{{ selected.product }}</h4>
      <div class="facts-row">
        <div class="fact"><span>Подразделение</span><b>{{ selected.department }}</b></div>
        <div class="fact"><span>Производитель</span><b>{{ selected.manufacturer || '—' }}</b></div>
        <div class="fact"><span>Поставщик</span><b>{{ selected.supplier }}</b></div>
        <div class="fact"><span>Количество образцов</span><b>{{ selected.sampleQuantity || '—' }} шт.</b></div>
      </div>
      <div class="method-row"><span>Метод испытаний</span><p>{{ selected.testMethod || '—' }}</p></div>
    </article>
    <div class="request-grid">
      <div class="stack">
        <article v-if="hasHeroAction || actionError" class="card hero">
          <div v-if="selected.canAssignExecutor || selected.canReject" class="hero-block">
            <div class="action-row action-row--manager has-help">
              <label v-if="selected.canAssignExecutor" class="inline-field"><span class="visually-hidden">Исполнитель ИЦ</span><select v-model="executorChoice" :disabled="actionLoading" aria-label="Исполнитель ИЦ"><option value="">Выберите сотрудника</option><option v-for="executor in executors" :key="executor.id" :value="executor.id">{{ executor.displayName }}</option></select></label>
              <button v-if="selected.canAssignExecutor" type="button" class="primary" :disabled="actionLoading || !executorChoice" @click="assignExecutor">{{ actionLoading ? 'Сохранение…' : (selected.executorId ? 'Переназначить' : 'Назначить') }}</button>
              <button v-if="selected.canStart" type="button" class="primary" :class="{ 'is-disabled': !canStartAction }" :aria-disabled="!canStartAction" :disabled="actionLoading" @click="handleStartClick">{{ actionLoading ? 'Запуск…' : 'Начать работу' }}</button>
              <button v-else-if="selected.canSuspend" type="button" class="secondary" :disabled="suspendResumeLoading" @click="suspendOrResumeRequest('suspend')">{{ suspendResumeLoading ? 'Сохранение…' : 'Приостановить работу' }}</button>
              <button v-else-if="selected.canResume" type="button" class="primary" :disabled="suspendResumeLoading" @click="suspendOrResumeRequest('resume')">{{ suspendResumeLoading ? 'Сохранение…' : 'Возобновить работу' }}</button>
              <button v-if="selected.canReject" type="button" class="secondary danger" :disabled="rejectLoading" @click="rejectRequest">{{ rejectLoading ? 'Сохранение…' : 'Отказать в проведении испытаний' }}</button>
              <a class="help-icon" href="/help/assignment.html" target="_blank" title="Инструкция по назначению и началу работы" aria-label="Инструкция по назначению и началу работы">?</a>
            </div>
            <p v-if="selected.canStart && startHintRevealed && startHint" class="hero-hint">{{ startHint }}</p>
            <p v-if="suspendResumeError" class="action-error">{{ suspendResumeError }}</p>
            <p v-if="rejectError" class="action-error">{{ rejectError }}</p>
          </div>

          <div v-if="!selected.canAssignExecutor && !selected.canReject && (selected.canStart || selected.canSuspend || selected.canResume)" class="hero-block">
            <div class="action-row action-row--workflow has-help">
              <button v-if="selected.canStart" type="button" class="primary" :class="{ 'is-disabled': !canStartAction }" :aria-disabled="!canStartAction" :disabled="actionLoading" @click="handleStartClick">{{ actionLoading ? 'Запуск…' : 'Начать работу' }}</button>
              <button v-else-if="selected.canSuspend" type="button" class="secondary" :disabled="suspendResumeLoading" @click="suspendOrResumeRequest('suspend')">{{ suspendResumeLoading ? 'Сохранение…' : 'Приостановить работу' }}</button>
              <button v-else-if="selected.canResume" type="button" class="primary" :disabled="suspendResumeLoading" @click="suspendOrResumeRequest('resume')">{{ suspendResumeLoading ? 'Сохранение…' : 'Возобновить работу' }}</button>
              <a class="help-icon" href="/help/assignment.html" target="_blank" title="Инструкция по назначению и началу работы" aria-label="Инструкция по назначению и началу работы">?</a>
            </div>
            <p v-if="selected.canStart && startHintRevealed && startHint" class="hero-hint">{{ startHint }}</p>
            <p v-if="suspendResumeError" class="action-error">{{ suspendResumeError }}</p>
          </div>

          <div v-if="selected.canUploadReport || selected.canDeleteReport" class="hero-block">
            <h4>{{ !selected.canUploadReport ? 'Отчёт испытаний' : selected.canDeleteReport ? 'Загрузить новую версию отчёта' : 'Загрузите отчёт испытаний' }}</h4>
            <p v-if="selected.canUploadReport && !selected.canDeleteReport" class="hero-sub">Заявка перейдёт на подготовку экспертного заключения сразу после загрузки</p>
            <div class="hero-actions">
              <label v-if="selected.canUploadReport" class="primary upload-button">{{ reportLoading ? 'Загрузка отчёта…' : 'Загрузить отчёт испытаний' }}<input type="file" :disabled="reportLoading" accept=".pdf,application/pdf" @change="uploadReport" /></label>
              <button v-if="selected.canDeleteReport" type="button" class="secondary danger" :disabled="deleteReportLoading" @click="deleteReport">{{ deleteReportLoading ? 'Удаление…' : 'Удалить отчёт' }}</button>
            </div>
            <p v-if="reportError" class="action-error">{{ reportError }}</p>
            <p v-if="deleteReportError" class="action-error">{{ deleteReportError }}</p>
            <a v-if="selected.canUploadReport" class="help-icon" href="/help/report.html" target="_blank" title="Инструкция по загрузке отчёта испытаний" aria-label="Инструкция по загрузке отчёта испытаний">?</a>
          </div>

          <div v-if="selected.canClaimExpert" class="hero-block">
            <h4>Взять заявку в работу</h4>
            <p class="hero-sub">Вы станете экспертом, готовящим заключение по этой заявке</p>
            <div class="hero-actions"><button type="button" class="primary" :disabled="claimLoading" @click="claimExpert">{{ claimLoading ? 'Сохранение…' : 'Взять в работу' }}</button></div>
            <p v-if="claimError" class="action-error">{{ claimError }}</p>
            <a class="help-icon" href="/help/expert-opinion.html" target="_blank" title="Инструкция по формированию заключения" aria-label="Инструкция по формированию заключения">?</a>
          </div>

          <div v-if="selected.canPublishOpinion || selected.canReassignExpert" class="hero-block">
            <div class="action-row action-row--expert has-help">
              <div v-if="selected.canPublishOpinion" class="expert-action-group expert-action-group--primary">
                <span class="action-group-label">Заключение</span>
                <button type="button" class="primary" :disabled="opinionLoading" @click="openOpinionModal">Написать заключение</button>
              </div>
              <div v-if="selected.canReassignExpert" class="expert-action-group expert-action-group--reassign">
                <span class="action-group-label">Переназначение</span>
                <select v-model="expertChoice" :disabled="reassignLoading" aria-label="Новый эксперт"><option value="">Выберите эксперта</option><option v-for="expert in experts.filter(candidate => candidate.id !== selected.expertId)" :key="expert.id" :value="expert.id">{{ expert.displayName }}</option></select>
                <button type="button" class="secondary" :disabled="reassignLoading || !expertChoice" @click="reassignExpert">{{ reassignLoading ? 'Сохранение…' : 'Переназначить' }}</button>
              </div>
              <a class="help-icon" href="/help/expert-opinion.html" target="_blank" title="Инструкция по формированию заключения" aria-label="Инструкция по формированию заключения">?</a>
            </div>
            <p v-if="reassignError" class="action-error">{{ reassignError }}</p>
          </div>

          <div v-if="selected.canSecurityDecide" class="hero-block">
            <div class="action-row has-help">
              <button type="button" class="primary confirm" :disabled="securityLoading" @click="decideSecurity('approve')">{{ securityLoading ? 'Сохранение…' : 'Согласовать и завершить' }}</button>
              <button type="button" class="secondary" :disabled="securityLoading" @click="decideSecurity('return')">Вернуть в работу</button>
              <a class="help-icon" href="/help/security-review.html" target="_blank" title="Инструкция по контролю СБ" aria-label="Инструкция по контролю СБ">?</a>
            </div>
            <p v-if="securityError" class="action-error">{{ securityError }}</p>
          </div>

          <div v-if="selected.canWithdraw" class="hero-block">
            <button type="button" class="secondary danger" :disabled="withdrawLoading" @click="withdrawRequest">{{ withdrawLoading ? 'Сохранение…' : 'Отозвать заявку' }}</button>
            <p v-if="withdrawError" class="action-error">{{ withdrawError }}</p>
          </div>
          <p v-if="actionError" class="action-error">{{ actionError }}</p>
        </article>

        <article class="card feed">
          <div class="section-title"><h3>Лента заявки <span>{{ feed.length }}</span></h3></div>
          <form v-if="canSubmitComment(selected, detailLoading)" class="comment-input" @submit.prevent="addComment"><span class="avatar small">{{ currentInitials }}</span><input v-model="commentDraft" :disabled="commentLoading" maxlength="10000" placeholder="Оставьте комментарий…" /><button :disabled="commentLoading">➤</button></form>
          <p v-else class="placeholder-copy">На текущем этапе новые комментарии недоступны.</p>
          <p v-if="commentError" class="action-error">{{ commentError }}</p>
          <div class="stream">
            <div v-for="entry in feed" :key="`${entry.type}-${entry.id}`" class="entry" :class="{ system: entry.type === 'milestone' }">
              <span class="avatar small" :class="{ 'blue-avatar': entry.type === 'comment' }">●</span>
              <div class="entry-body">
                <template v-if="entry.type === 'milestone'">
                  <div class="entry-head"><b>{{ entry.actor }} — {{ entry.description }}</b><time>{{ entry.occurredAt }} · {{ entry.ruleId }}</time></div>
                  <button v-if="entry.versionId && entry.originalName" type="button" class="feed-document-link" @click="downloadDocument(entry)">{{ entry.originalName }}</button>
                </template>
                <template v-else>
                  <div class="entry-head"><b>{{ entry.author }}</b><time>{{ entry.createdAt }}</time></div>
                  <p>{{ entry.body }}</p>
                </template>
              </div>
            </div>
          </div>
          <p v-if="!feed.length" class="placeholder-copy">Лента пока пуста.</p>
          <button v-if="selected.commentsPage?.hasMore" class="secondary" :disabled="olderCommentsLoading" @click="loadOlderComments">{{ olderCommentsLoading ? 'Загрузка…' : 'Показать предыдущие' }}</button>
        </article>
      </div>
      <aside class="stack side-column">
        <article class="card"><h3>Статус</h3>
          <div class="fact-list">
            <div class="fact"><span>Инициатор</span><b>{{ selected.initiator }}</b></div>
            <div class="fact"><span>Исполнитель</span><b>{{ selected.executor }}</b></div>
            <div class="fact"><span>Эксперт</span><b>{{ selected.expert }}</b></div>
            <div class="fact"><span>Отметка СБ</span><b><span class="security-mark-icon" :class="selected.securityMarkDisplay?.className" :title="selected.securityMarkDisplay?.label" :aria-label="selected.securityMarkDisplay?.label"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path :d="selected.securityMarkDisplay?.path" /></svg></span></b></div>
          </div>
        </article>
        <article class="card documents"><h3>Документы <span>{{ selected.documents?.length || 0 }}</span></h3>
          <button v-for="document in selected.documents || []" :key="document.versionId" class="document-row" @click="downloadDocument(document)"><span class="doc-icon" :class="documentKind(document.mimeType).className">{{ documentKind(document.mimeType).label }}</span><span><b>{{ document.title }}</b><small>Версия {{ document.version }} · {{ document.size }} · {{ document.createdAt }}</small></span></button>
          <p v-if="!selected.documents?.length" class="placeholder-copy">Документов пока нет.</p>
          <label v-if="selected.canUploadDocument" class="secondary upload-button">{{ documentLoading ? 'Загрузка…' : 'Загрузить документ' }}<input type="file" :disabled="documentLoading" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" @change="uploadDocument" /></label>
          <p v-if="documentError" class="action-error">{{ documentError }}</p>
        </article>
      </aside>
    </div>
  </section>
  <div v-if="confirmDialog.state.open" class="overlay" @click.self="confirmDialog.cancel">
    <div class="modal confirm-modal">
      <p>{{ confirmDialog.state.message }}</p>
      <label v-if="confirmDialog.state.reasonField" class="confirm-reason-field">
        Причина{{ confirmDialog.state.reasonField.required ? '' : ' (необязательно)' }}
        <textarea
          v-model="confirmDialog.state.reasonValue"
          maxlength="5000"
          :placeholder="confirmDialog.state.reasonField.placeholder"
        ></textarea>
      </label>
      <div class="modal-actions">
        <button type="button" class="secondary" @click="confirmDialog.cancel">Отмена</button>
        <button
          type="button"
          class="primary"
          :class="{ danger: confirmDialog.state.danger, confirm: confirmDialog.state.confirm }"
          :disabled="confirmDialog.state.reasonField?.required && !confirmDialog.state.reasonValue.trim()"
          @click="confirmDialog.accept"
        >{{ confirmDialog.state.confirmLabel }}</button>
      </div>
    </div>
  </div>

  <div v-if="showOpinionModal" class="overlay" @click.self="!opinionLoading && (showOpinionModal = false)">
    <form class="modal" @submit.prevent="publishOpinion">
      <div class="modal-head"><h2>Экспертное заключение</h2><button type="button" :disabled="opinionLoading" @click="showOpinionModal = false">×</button></div>
      <div class="fact-list opinion-summary">
        <div class="fact"><span>Объект испытаний</span><b>{{ selected.product }}</b></div>
        <div class="fact"><span>Производитель</span><b>{{ selected.manufacturer || '—' }}</b></div>
        <div class="fact"><span>Поставщик</span><b>{{ selected.supplier }}</b></div>
        <div class="fact"><span>Количество образцов</span><b>{{ selected.sampleQuantity || '—' }} шт.</b></div>
        <div class="fact wide"><span>Метод испытаний</span><b>{{ selected.testMethod || '—' }}</b></div>
      </div>
      <textarea v-model="opinionDraft" :disabled="opinionLoading" minlength="10" maxlength="20000" placeholder="Введите итоговое заключение по результатам испытаний"></textarea>
      <p v-if="opinionError" class="action-error">{{ opinionError }}</p>
      <div class="modal-actions">
        <button type="button" class="secondary" :disabled="opinionLoading" @click="showOpinionModal = false">Отмена</button>
        <button class="primary" :disabled="opinionLoading">{{ opinionLoading ? 'Публикация…' : 'Опубликовать и передать в СБ' }}</button>
      </div>
    </form>
  </div>
</template>
