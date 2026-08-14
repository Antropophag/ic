<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import { createConfirmDialog } from '../confirmDialog'
import { confirmRequestAction } from '../confirmRequestAction'
import { triggerBlobDownload } from '../download'
import { createLatestRequestGuard } from '../latestRequestGuard'
import { documentKind } from '../registry'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'

const props = defineProps({ request: { type: Object, required: true }, refresh: { type: Function, required: true } })
const documentLoading = ref(false)
const documentError = ref('')
const reportLoading = ref(false)
const reportError = ref('')
const deleteReportLoading = ref(false)
const deleteReportError = ref('')
const showTestActModal = ref(false)
const testActDraft = ref({ documentType: 'test_act', actNumber: '', actDate: '', basis: '', result: '', sampleName: '', testMethod: '', requestNumber: null })
const testActLoading = ref(false)
const testActError = ref('')
const documentRequestGuard = createLatestRequestGuard()
const reportRequestGuard = createLatestRequestGuard()
const deleteReportRequestGuard = createLatestRequestGuard()
const testActRequestGuard = createLatestRequestGuard()
const downloadRequestGuard = createLatestRequestGuard()
const previewRequestGuard = createLatestRequestGuard()
const confirmDialog = createConfirmDialog()

const documentGroups = computed(() => {
  const documents = props.request.documents || []
  return [
    { key: 'attachment', label: 'Сопроводительные документы', items: documents.filter(document => !['report', 'opinion'].includes(document.documentType)) },
    { key: 'report', label: 'Отчётные документы', items: documents.filter(document => document.documentType === 'report') },
    { key: 'opinion', label: 'Экспертное заключение', items: documents.filter(document => document.documentType === 'opinion') },
  ].filter(group => group.items.length || (group.key === 'report' && props.request.canUploadReport))
})

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

async function uploadDocument(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const requestId = props.request.backendId
  const token = documentRequestGuard.begin(requestId)
  documentLoading.value = true
  documentError.value = ''
  try {
    await requestApi.uploadDocument(requestId, file)
    if (!documentRequestGuard.isCurrent(token, props.request.backendId)) return
    await props.refresh(requestId)
  } catch (error) {
    if (!documentRequestGuard.isCurrent(token, props.request.backendId)) return
    documentError.value = error.status === 413
      ? 'Файл слишком большой. Максимальный размер — 200 МБ.'
      : error.status === 422
        ? 'Разрешены PDF, PNG, JPG, DOCX и XLSX размером до 200 МБ.'
        : error.status === 409
          ? 'На текущем этапе загружать документы нельзя.'
          : 'Не удалось загрузить документ.'
  } finally {
    if (documentRequestGuard.isCurrent(token, props.request.backendId)) documentLoading.value = false
  }
}

async function uploadReport(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const requestId = props.request.backendId
  const token = reportRequestGuard.begin(requestId)
  reportLoading.value = true
  reportError.value = ''
  try {
    await requestApi.uploadReport(requestId, file)
    if (reportRequestGuard.isCurrent(token, props.request.backendId)) await props.refresh(requestId, { emitUpdated: true })
  } catch (error) {
    if (!reportRequestGuard.isCurrent(token, props.request.backendId)) return
    reportError.value = error.status === 413
      ? 'Файл слишком большой. Максимальный размер отчёта — 200 МБ.'
      : error.status === 422
        ? 'Отчёт должен быть PDF-файлом размером до 200 МБ.'
        : error.status === 403
          ? 'Загрузить отчёт может назначенный исполнитель или руководитель.'
          : 'Не удалось загрузить отчёт.'
  } finally {
    if (reportRequestGuard.isCurrent(token, props.request.backendId)) reportLoading.value = false
  }
}

async function deleteReport() {
  if (deleteReportLoading.value) return
  const context = await confirmRequestAction(
    () => props.request,
    () => confirmDialog.ask('Удалить загруженный отчёт испытаний? Отчёт и заключение по нему станут недоступны.', {
      confirmLabel: 'Удалить', danger: true,
      reasonField: { required: true, placeholder: 'Опишите причину удаления отчёта' },
    }),
  )
  if (!context) return
  const { requestId, lockVersion, confirmed } = context
  const token = deleteReportRequestGuard.begin(requestId)
  deleteReportLoading.value = true
  deleteReportError.value = ''
  try {
    await requestApi.deleteReport(requestId, lockVersion, confirmed.reason)
    if (!deleteReportRequestGuard.isCurrent(token, props.request.backendId)) return
    try { await props.refresh(requestId) } catch { deleteReportError.value = 'Отчёт удалён, но данные на экране не обновились. Обновите страницу перед следующим действием.' }
  } catch (error) {
    if (!deleteReportRequestGuard.isCurrent(token, props.request.backendId)) return
    if (error.status === 409) {
      await props.refresh(requestId, { suppressStaleActions: true })
      deleteReportError.value = 'Заявка уже изменена. Данные обновлены — проверьте актуальный статус.'
    } else deleteReportError.value = error.status === 403 ? 'Удалить отчёт может только исполнитель или руководитель.' : 'Не удалось удалить отчёт. Обновите страницу и повторите попытку.'
  } finally {
    if (deleteReportRequestGuard.isCurrent(token, props.request.backendId)) deleteReportLoading.value = false
  }
}

async function downloadDocument(document) {
  const requestId = props.request.backendId
  const token = downloadRequestGuard.begin(requestId)
  documentError.value = ''
  try {
    const blob = await requestApi.downloadDocument(document.versionId)
    if (downloadRequestGuard.isCurrent(token, props.request.backendId)) triggerBlobDownload(blob, document.originalName)
  } catch {
    if (downloadRequestGuard.isCurrent(token, props.request.backendId)) documentError.value = 'Не удалось скачать документ.'
  }
}

async function openDocument(document) {
  const requestId = props.request.backendId
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
    if (!previewRequestGuard.isCurrent(token, props.request.backendId)) {
      previewWindow.close()
      return
    }
    const url = URL.createObjectURL(blob)
    previewWindow.location.replace(url)
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch {
    previewWindow.close()
    if (previewRequestGuard.isCurrent(token, props.request.backendId)) documentError.value = 'Не удалось открыть документ. Попробуйте скачать файл.'
  }
}

async function openTestActModal() {
  const requestId = props.request.backendId
  const token = testActRequestGuard.begin(requestId)
  testActLoading.value = true
  testActError.value = ''
  showTestActModal.value = true
  try {
    const draft = await requestApi.prepareTestAct(requestId)
    if (testActRequestGuard.isCurrent(token, props.request.backendId)) testActDraft.value = { ...draft, documentType: 'test_act', result: '' }
  } catch (error) {
    if (!testActRequestGuard.isCurrent(token, props.request.backendId)) return
    testActError.value = error.status === 403 ? 'Сформировать акт может назначенный исполнитель или руководитель.' : 'Не удалось подготовить данные акта.'
  } finally {
    if (testActRequestGuard.isCurrent(token, props.request.backendId)) testActLoading.value = false
  }
}

function closeTestActModal() {
  testActRequestGuard.invalidate()
  testActLoading.value = false
  showTestActModal.value = false
}

async function generateTestAct() {
  const requestId = props.request.backendId
  const token = testActRequestGuard.begin(requestId)
  testActLoading.value = true
  testActError.value = ''
  try {
    const { actNumber, actDate, basis, result, requestNumber } = testActDraft.value
    const blob = await requestApi.generateTestAct(requestId, { actNumber, actDate, basis, result })
    if (!testActRequestGuard.isCurrent(token, props.request.backendId)) return
    triggerBlobDownload(blob, `Акт_испытаний_заявка_${requestNumber}.docx`)
    closeTestActModal()
  } catch (error) {
    if (!testActRequestGuard.isCurrent(token, props.request.backendId)) return
    testActError.value = error.payload?.errors?.result?.[0]
      || error.payload?.errors?.actDate?.[0]
      || (error.status === 403 ? 'У вас больше нет права формировать акт по этой заявке.' : error.message)
      || 'Не удалось сформировать DOCX.'
  } finally {
    if (testActRequestGuard.isCurrent(token, props.request.backendId)) testActLoading.value = false
  }
}

function invalidate() {
  for (const guard of [documentRequestGuard, reportRequestGuard, deleteReportRequestGuard, testActRequestGuard, downloadRequestGuard, previewRequestGuard]) guard.invalidate()
  confirmDialog.cancel()
  documentLoading.value = false
  reportLoading.value = false
  deleteReportLoading.value = false
  testActLoading.value = false
  showTestActModal.value = false
}
watch(() => props.request.backendId, () => {
  invalidate()
  documentError.value = ''
  reportError.value = ''
  deleteReportError.value = ''
  testActError.value = ''
})
onBeforeUnmount(invalidate)

defineExpose({ deleteReport, deleteReportError, deleteReportLoading, documentError, downloadDocument, openDocument, reportError, reportLoading, uploadReport })
</script>

<template>
  <article id="request-documents" class="card documents request-documents"><div class="section-title request-documents-head"><h3>Документы <span class="request-document-count" :aria-label="`Документов: ${request.documents?.length || 0}`">{{ request.documents?.length || 0 }}</span></h3><label v-if="request.canUploadDocument" class="request-document-upload"><AppIcon v-if="!documentLoading" name="plus" :size="14" />{{ documentLoading ? 'Загрузка…' : 'Добавить' }}<input type="file" :disabled="documentLoading" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" @change="uploadDocument" /></label></div>
    <section v-for="group in documentGroups" :key="group.key" class="request-document-group" :aria-labelledby="`document-group-${group.key}`"><h4 :id="`document-group-${group.key}`"><span class="request-document-group-label">{{ group.label }} <span>{{ group.items.length }}</span></span><button v-if="group.key === 'report' && request.canUploadReport" type="button" class="request-document-group-action app-tooltip app-tooltip-left" data-tooltip="Сформировать шаблон отчётного документа" aria-label="Сформировать шаблон отчётного документа" :disabled="testActLoading" @click="openTestActModal"><AppIcon name="magic-wand" :size="17" /></button></h4><div v-for="document in group.items" :key="document.versionId" class="document-row request-file-card"><button type="button" class="request-file-open app-tooltip" data-tooltip="Открыть документ" :aria-label="`Открыть ${document.title}, версия ${document.version}`" @click="openDocument(document)"><span class="request-file-thumb" aria-hidden="true"><span class="request-file-lines"></span><span class="request-file-type" :class="fileTypeClassFor(document)">{{ fileExtensionFor(document) }}</span></span><span class="request-file-copy"><b :title="document.title">{{ document.title }}</b><small>Версия {{ document.version }} · {{ document.size }}</small><small>{{ document.createdAt }}</small></span></button><button type="button" class="request-file-action app-tooltip" data-tooltip="Скачать документ" :aria-label="`Скачать ${document.title}`" @click.stop="downloadDocument(document)"><AppIcon name="download" :size="14" /></button></div></section>
    <p v-if="!request.documents?.length" class="placeholder-copy">Документов пока нет.</p>
    <p v-if="documentError" class="action-error">{{ documentError }}</p>
  </article>

  <AppModal :open="showTestActModal" as="form" title="Сформировать шаблон документа" subtitle="Экспериментальная функция" title-id="test-act-modal-title" size="large" :busy="testActLoading" @close="closeTestActModal" @submit="generateTestAct">
    <div class="test-act-document-type"><span id="test-document-type-label">Тип документа</span><div class="test-document-tabs" role="tablist" aria-labelledby="test-document-type-label"><button type="button" role="tab" :aria-selected="testActDraft.documentType === 'test_act'" class="active" :disabled="testActLoading" @click="testActDraft.documentType = 'test_act'"><span>Акт испытаний</span><small>DOCX</small></button><button type="button" role="tab" aria-selected="false" disabled><span>Протокол испытаний</span><span class="test-document-tab-meta"><small>DOCX</small><small class="test-document-soon">Скоро</small></span></button></div></div>
    <div class="fact-list opinion-summary test-act-summary"><div class="fact wide"><span>Наименование образца</span><b>{{ testActDraft.sampleName || '—' }}</b></div><div class="fact wide"><span>Программа / метод испытаний</span><b>{{ testActDraft.testMethod || '—' }}</b></div></div>
    <div class="form-grid test-act-fields"><label>Номер акта<input v-model.trim="testActDraft.actNumber" :disabled="testActLoading" maxlength="100" required /></label><label>Дата акта<input v-model.trim="testActDraft.actDate" :disabled="testActLoading" maxlength="10" placeholder="дд.мм.гггг" required /></label><label class="wide">Основание проведения испытаний<input v-model.trim="testActDraft.basis" :disabled="testActLoading" maxlength="1000" required /></label><label class="wide">Результат испытаний<textarea v-model.trim="testActDraft.result" :disabled="testActLoading" maxlength="20000" required placeholder="Опишите фактический результат испытаний"></textarea></label></div>
    <p class="placeholder-copy">Черновик скачивается для редактирования в Word и не становится итоговым отчётом заявки.</p><p v-if="testActError" class="action-error">{{ testActError }}</p>
    <template #footer><button type="button" class="secondary" :disabled="testActLoading" @click="closeTestActModal">Отмена</button><button type="submit" class="primary" :disabled="testActLoading || !testActDraft.actNumber.trim() || !testActDraft.actDate.trim() || !testActDraft.basis.trim() || !testActDraft.result.trim()">{{ testActLoading ? 'Формирование…' : 'Сформировать шаблон' }}</button></template>
  </AppModal>
  <AppModal :open="confirmDialog.state.open" title="Подтвердите действие" title-id="request-document-confirm-title" description-id="request-document-confirm-message" size="small" alert @close="confirmDialog.cancel">
    <p id="request-document-confirm-message">{{ confirmDialog.state.message }}</p>
    <label v-if="confirmDialog.state.reasonField" class="confirm-reason-field"><span class="visually-hidden">Причина действия</span><textarea v-model="confirmDialog.state.reasonValue" maxlength="5000" :placeholder="confirmDialog.state.reasonField.placeholder"></textarea></label>
    <template #footer><button type="button" class="secondary" @click="confirmDialog.cancel">Отмена</button><button type="button" class="primary" :class="{ danger: confirmDialog.state.danger }" :disabled="confirmDialog.state.reasonField?.required && !confirmDialog.state.reasonValue.trim()" @click="confirmDialog.accept">{{ confirmDialog.state.confirmLabel }}</button></template>
  </AppModal>
</template>
