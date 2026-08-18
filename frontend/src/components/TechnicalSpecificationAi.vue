<script>
import { markRaw, reactive } from 'vue'
const taskSessions = new Map()
let authenticatedPrincipalId = null

function idleTask() {
  return { status: 'idle', data: null, error: '', retryWithNewIntent: false }
}

function taskSession(requestId) {
  const key = `${authenticatedPrincipalId ?? 'anonymous'}:${requestId}`
  if (!taskSessions.has(key)) {
    taskSessions.set(key, reactive({
      analysisTask: idleTask(),
      draftTask: idleTask(),
      selectedVersionId: null,
      completedRevision: 0,
      seenRevision: 0,
      analysisController: null,
      draftController: null,
    }))
  }
  return taskSessions.get(key)
}

function cancelTaskSession(session) {
  const analysisController = session.analysisController
  const draftController = session.draftController
  if (analysisController === null && draftController === null) return
  session.analysisController = null
  session.draftController = null
  session.analysisTask = idleTask()
  session.draftTask = idleTask()
  session.selectedVersionId = null
  session.completedRevision = 0
  session.seenRevision = 0
  analysisController?.abort()
  draftController?.abort()
}

export function resetTechnicalSpecificationAiSessions() {
  for (const session of taskSessions.values()) {
    cancelTaskSession(session)
  }
  taskSessions.clear()
}

export function setTechnicalSpecificationAiPrincipal(principalId) {
  const nextPrincipalId = principalId === null || principalId === undefined ? null : String(principalId)
  if (nextPrincipalId === authenticatedPrincipalId) return
  resetTechnicalSpecificationAiSessions()
  authenticatedPrincipalId = nextPrincipalId
}
</script>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'

const props = defineProps({ requestId: { type: Number, required: true }, showTrigger: { type: Boolean, default: true } })
const open = ref(false)
const activeTab = ref('analysis')
const session = ref(taskSession(props.requestId))
const selectedVersionId = computed(() => session.value.selectedVersionId)
const analysisTask = computed(() => session.value.analysisTask)
const draftTask = computed(() => session.value.draftTask)
const hasUnreadResult = computed(() => session.value.completedRevision > session.value.seenRevision)
const isWorking = computed(() => analysisTask.value.status === 'loading' || draftTask.value.status === 'loading')
const loadingCopyIndex = ref(0)
let loadingCopyTimer = null

const analysisLoadingCopy = [
  'Сверяем требования с заводской реальностью',
  'Ищем, куда укатился недостающий допуск',
  'Поднимаем нормативы с дальней полки',
  'Отделяем техническое задание от технического пожелания',
  'Проверяем формулировки штангенциркулем здравого смысла',
  'Сводим противоречия — смену пока не сводим',
]
const draftLoadingCopy = [
  'Собираем черновик без молотка и напильника',
  'Раскладываем требования по технологическим полкам',
  'Прикидываем испытания семь раз перед одним черновиком',
  'Ищем открытые вопросы до того, как они найдут нас',
  'Комплектуем документ словами по спецификации',
  'Готовим бумагу, которую не стыдно отдать в работу',
]

const sectionLabels = {
  criticalContradictions: 'Критические противоречия',
  ambiguousRequirements: 'Неоднозначные или непроверяемые требования',
  missingInformation: 'Недостающая информация',
  testRequirements: 'Требования, требующие испытаний',
  initiatorQuestions: 'Вопросы инициатору',
  recommendations: 'Рекомендации',
}
const documentChoice = computed(() => {
  if (analysisTask.value.status === 'choice_required') return analysisTask.value.data.documents
  if (draftTask.value.status === 'choice_required') return draftTask.value.data.documents
  return null
})
const idle = computed(() => analysisTask.value.status === 'idle' && draftTask.value.status === 'idle')

function messageFor(errorValue, task) {
  if (errorValue.status === 403 || errorValue.status === 404) return 'Заявка или документ недоступны.'
  if (errorValue.status === 422) return errorValue.message || 'Не удалось прочитать выбранный документ.'
  if (errorValue.status === 409) return errorValue.message || 'Такая AI-операция уже выполняется.'
  if (errorValue.status === 503) return errorValue.message || 'ЛИЗА временно недоступна. Повторите попытку позже.'
  return task === 'analysis'
    ? 'Не удалось выполнить AI-анализ. Основная работа с заявкой не затронута.'
    : 'Не удалось сформировать черновик. Основная работа с заявкой не затронута.'
}

function loadingCopy(task) {
  const messages = task === 'analysis' ? analysisLoadingCopy : draftLoadingCopy
  return messages[loadingCopyIndex.value % messages.length]
}

function registerCompletion(target) {
  target.completedRevision += 1
  if (open.value && session.value === target) target.seenRevision = target.completedRevision
}

function runAnalysis(documentVersionId = null, newOperation = true) {
  const target = session.value
  target.analysisController?.abort()
  target.analysisController = markRaw(new AbortController())
  const controller = target.analysisController
  const requestId = props.requestId
  target.analysisTask = { status: 'loading', data: null, error: '' }
  void requestApi.analyzeTechnicalSpecification(requestId, documentVersionId, controller.signal, newOperation)
    .then(result => {
      if (target.analysisController === controller) {
        target.analysisTask = { status: result.status === 'completed' ? 'success' : result.status, data: result, error: '' }
        if (result.status === 'completed') registerCompletion(target)
      }
    })
    .catch(error => {
      if (target.analysisController !== controller) return
      target.analysisTask = error?.name === 'AbortError'
        ? idleTask()
        : { status: 'error', data: null, error: messageFor(error, 'analysis'), retryWithNewIntent: Number.isInteger(error?.status) }
    })
    .finally(() => { if (target.analysisController === controller) target.analysisController = null })
}

function runDraft(documentVersionId = null, newOperation = true) {
  const target = session.value
  target.draftController?.abort()
  target.draftController = markRaw(new AbortController())
  const controller = target.draftController
  const requestId = props.requestId
  target.draftTask = { status: 'loading', data: null, error: '' }
  void requestApi.createTestSpecificationDraft(requestId, documentVersionId, controller.signal, newOperation)
    .then(result => {
      if (target.draftController === controller) {
        target.draftTask = { status: result.status === 'completed' ? 'success' : result.status, data: result, error: '' }
        if (result.status === 'completed') registerCompletion(target)
      }
    })
    .catch(error => {
      if (target.draftController !== controller) return
      target.draftTask = error?.name === 'AbortError'
        ? idleTask()
        : { status: 'error', data: null, error: messageFor(error, 'draft'), retryWithNewIntent: Number.isInteger(error?.status) }
    })
    .finally(() => { if (target.draftController === controller) target.draftController = null })
}

function startBoth(documentVersionId = null) {
  session.value.selectedVersionId = documentVersionId
  runAnalysis(documentVersionId)
  runDraft(documentVersionId)
}

function show() {
  open.value = true
  session.value.seenRevision = session.value.completedRevision
}

function close() {
  open.value = false
  cancelTaskSession(session.value)
}

function switchRequest(requestId) {
  cancelTaskSession(session.value)
  open.value = false
  activeTab.value = 'analysis'
  session.value = taskSession(requestId)
}

watch(() => props.requestId, switchRequest)
watch(() => open.value && (analysisTask.value.status === 'loading' || draftTask.value.status === 'loading'), rotating => {
  if (loadingCopyTimer !== null) window.clearInterval(loadingCopyTimer)
  loadingCopyTimer = rotating ? window.setInterval(() => { loadingCopyIndex.value += 1 }, 4200) : null
}, { immediate: true })
onBeforeUnmount(() => {
  cancelTaskSession(session.value)
  open.value = false
  if (loadingCopyTimer !== null) window.clearInterval(loadingCopyTimer)
})
defineExpose({ show, hasUnreadResult, isWorking })
</script>

<template>
  <button v-if="showTrigger" type="button" class="request-ai-action app-tooltip app-tooltip-left" :class="{ 'is-working': isWorking }" data-tooltip="AI-анализ" aria-label="AI-анализ" :aria-busy="isWorking" @click="show"><span class="request-ai-pulse" aria-hidden="true"><svg viewBox="0 0 16 16"><path class="request-ai-pulse-wave" d="M2 8h2l1-3 2 6 2-6 2 6 1-3h2" /><circle cx="2" cy="8" r=".7" /><circle cx="14" cy="8" r=".7" /></svg></span><span v-if="hasUnreadResult" class="request-ai-notification-dot" aria-hidden="true"></span></button>
  <AppModal :open="open" title="Обработка технического задания" subtitle="Экспериментальная AI-функция" title-id="technical-specification-ai-title" size="large" @close="close">
    <div class="request-ai-workspace">
      <div v-if="idle" class="request-ai-start"><p>ЛИЗА одновременно проанализирует исходное ТЗ и сформирует независимый черновик ТЗ на испытания.</p><button type="button" class="primary" @click="startBoth()">Запустить обработку</button></div>
      <div v-else-if="documentChoice" class="request-ai-choice"><p>Найдено несколько похожих документов. Выберите актуальное ТЗ для обеих задач:</p><button v-for="document in documentChoice" :key="document.versionId" type="button" @click="startBoth(document.versionId)"><span><b>{{ document.name }}</b><small>Версия {{ document.version }} · {{ document.mimeType.includes('pdf') ? 'PDF' : 'DOCX' }}</small></span><AppIcon name="arrow-right" :size="15" /></button></div>
      <template v-else>
        <div class="request-ai-tabs" role="tablist" aria-label="Результаты обработки ТЗ">
          <button id="ai-analysis-tab" type="button" role="tab" :aria-selected="activeTab === 'analysis'" aria-controls="ai-analysis-panel" @click="activeTab = 'analysis'">Анализ ТЗ <span v-if="analysisTask.status !== 'idle'" class="request-ai-tab-status" :data-status="analysisTask.status">{{ analysisTask.status === 'loading' ? 'В работе' : analysisTask.status === 'success' ? 'Готово' : analysisTask.status === 'error' ? 'Ошибка' : 'Нет ТЗ' }}</span></button>
          <button id="ai-draft-tab" type="button" role="tab" :aria-selected="activeTab === 'draft'" aria-controls="ai-draft-panel" @click="activeTab = 'draft'">Черновик ТЗ на испытания <span v-if="draftTask.status !== 'idle'" class="request-ai-tab-status" :data-status="draftTask.status">{{ draftTask.status === 'loading' ? 'В работе' : draftTask.status === 'success' ? 'Готово' : draftTask.status === 'error' ? 'Ошибка' : 'Нет ТЗ' }}</span></button>
        </div>
        <section v-show="activeTab === 'analysis'" id="ai-analysis-panel" role="tabpanel" aria-labelledby="ai-analysis-tab" tabindex="0">
          <div v-if="analysisTask.status === 'loading'" class="request-ai-loading" role="status" aria-live="polite"><span class="request-ai-spinner" aria-hidden="true"></span><span><b>{{ loadingCopy('analysis') }}</b><small>ЛИЗА анализирует документ. Заявка и документы не изменяются.</small></span></div>
          <div v-else-if="analysisTask.status === 'not_found'" class="request-ai-empty"><b>Техническое задание не найдено</b><p>{{ analysisTask.data.message }}</p><small>Добавьте PDF или DOCX с «ТЗ» или «техническое задание» в названии и повторите обработку.</small></div>
          <div v-else-if="analysisTask.status === 'success'" class="request-ai-result"><p class="request-ai-disclaimer">AI-результат носит справочный характер и ничего не меняет в заявке.</p><section v-for="(label, key) in sectionLabels" :key="key"><h4>{{ label }}</h4><ul v-if="analysisTask.data.analysis[key]?.length"><li v-for="(item, index) in analysisTask.data.analysis[key]" :key="index">{{ item }}</li></ul><p v-else class="placeholder-copy">Не выявлено.</p></section></div>
          <div v-else-if="analysisTask.status === 'error'" class="request-ai-empty" role="alert"><p class="action-error">{{ analysisTask.error }}</p><button type="button" class="primary" @click="runAnalysis(selectedVersionId, analysisTask.retryWithNewIntent)">Повторить анализ</button></div>
        </section>
        <section v-show="activeTab === 'draft'" id="ai-draft-panel" role="tabpanel" aria-labelledby="ai-draft-tab" tabindex="0">
          <div v-if="draftTask.status === 'loading'" class="request-ai-loading" role="status" aria-live="polite"><span class="request-ai-spinner" aria-hidden="true"></span><span><b>{{ loadingCopy('draft') }}</b><small>ЛИЗА формирует черновик. Результат появится здесь после завершения.</small></span></div>
          <div v-else-if="draftTask.status === 'not_found'" class="request-ai-empty"><b>Техническое задание не найдено</b><p>{{ draftTask.data.message }}</p></div>
          <div v-else-if="draftTask.status === 'success'" class="request-ai-result request-ai-draft"><p class="request-ai-disclaimer">Черновик требует проверки специалистом.</p><pre>{{ draftTask.data.draft }}</pre></div>
          <div v-else-if="draftTask.status === 'error'" class="request-ai-empty" role="alert"><p class="action-error">{{ draftTask.error }}</p><button type="button" class="primary" @click="runDraft(selectedVersionId, draftTask.retryWithNewIntent)">Повторить формирование</button></div>
        </section>
      </template>
    </div>
    <template #footer><button type="button" class="secondary" @click="close">Закрыть</button></template>
  </AppModal>
</template>
