<script setup>
import { computed, nextTick, ref, toRef, watch } from 'vue'
import { canStartNow, elapsedSince, requestStatusPresentation } from '../registry'
import { useRequestActions } from '../composables/useRequestActions'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'
import HelpArticle from './HelpArticle.vue'

const props = defineProps({
  request: { type: Object, required: true },
  refresh: { type: Function, required: true },
  documentWorkflow: { type: Object, default: null },
  currentUserRoles: { type: Array, default: () => [] },
  initialWarning: { type: String, default: '' },
})
const request = toRef(props, 'request')
const actions = useRequestActions(request, props.refresh)
watch(() => request.value.backendId, () => { actions.actionError.value = props.initialWarning }, { immediate: true })
const helpTrigger = ref(null)
const helpDrawer = ref(null)
const showHelpDrawer = ref(false)

const processSteps = computed(() => {
  const labels = ['Зарегистрирована', 'В работе', 'Экспертиза', 'Контроль СБ', 'Завершена']
  const presentation = requestStatusPresentation(request.value?.statusCode)
  const currentIndex = presentation.processIndex
  return labels.map((label, index) => ({
    label: index === currentIndex ? presentation.processLabel || label : label,
    state: index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'future',
    tone: index === currentIndex ? presentation.processTone || 'blue' : '',
    terminal: Boolean(presentation.terminal && index === currentIndex),
  }))
})
const stateAgeLabel = computed(() => {
  if (!request.value?.stateChangedAt) return ''
  const terminalLabel = requestStatusPresentation(request.value.statusCode).terminalDateLabel
  if (terminalLabel) {
    const date = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'long' }).format(new Date(request.value.stateChangedAt))
    return `${terminalLabel} ${date}`
  }
  return `В текущем статусе ${elapsedSince(request.value.stateChangedAt)}`
})
const isIcEmployee = computed(() => props.currentUserRoles.some(role => ['ic_executor', 'ic_manager', 'laboratory_manager'].includes(role)))
const statusReason = computed(() => {
  if (!request.value?.statusReason) return null
  if (request.value.status === 'Работы приостановлены' && !isIcEmployee.value) return { label: 'Причина приостановки', text: request.value.statusReason }
  if (request.value.status === 'В проведении испытаний отказано') return { label: 'Причина отказа', text: request.value.statusReason }
  if (request.value.status === 'Заявка отозвана') return { label: 'Причина отзыва', text: request.value.statusReason }
  return null
})
const canStartAction = computed(() => canStartNow(request.value))
const startHint = computed(() => {
  if (!request.value) return ''
  if (!request.value.canStart) return 'Работа по заявке уже начата.'
  if (!request.value.executorId) return 'Начать работу можно после назначения исполнителя.'
  return ''
})
const hasStaffAction = computed(() => Boolean(request.value && (
  request.value.canAssignExecutor || request.value.canStart || request.value.canUploadReport || request.value.canClaimExpert
  || request.value.canReassignExpert || request.value.canPublishOpinion || request.value.canSecurityDecide
  || request.value.canReject || request.value.canDeleteReport || request.value.canSuspend || request.value.canResume
)))
const hasHeroAction = computed(() => Boolean(request.value && (hasStaffAction.value || request.value.canWithdraw || request.value.isInitiator || statusReason.value)))
const actionPrompt = computed(() => {
  if (!request.value) return ''
  if (request.value.canSecurityDecide) return 'Проверьте заключение и примите решение'
  if (request.value.canPublishOpinion) return 'Подготовьте экспертное заключение'
  if (request.value.canReassignExpert) return 'Подготовьте заключение или передайте заявку эксперту'
  if (request.value.canClaimExpert) return 'Возьмите заявку на экспертизу'
  if (request.value.canUploadReport) return 'Завершите испытания и загрузите отчёт'
  if (request.value.canDeleteReport) return 'Проверьте загруженный отчёт'
  if (request.value.canResume) return 'Возобновите работы по заявке'
  if (request.value.canStart) return request.value.executorId ? 'Запустите работу по заявке' : 'Сначала назначьте исполнителя'
  if (request.value.canAssignExecutor) return 'Выберите и назначьте исполнителя'
  if (request.value.canSuspend) return 'Продолжите работу или приостановите её'
  if (statusReason.value && !hasStaffAction.value && !request.value.canWithdraw) return 'Сейчас от вас ничего не требуется'
  if (request.value.isInitiator && !hasStaffAction.value) return 'Сейчас от вас ничего не требуется'
  if (request.value.canWithdraw) return 'Заявка ожидает начала работ'
  if (request.value.canReject) return 'Примите заявку в работу или откажите'
  return 'Выберите действие по заявке'
})
const actionHelp = computed(() => {
  if (!request.value) return null
  if (request.value.canSecurityDecide) return { href: '/help/security-review.html', label: 'Инструкция по контролю СБ' }
  if (request.value.canPublishOpinion || request.value.canClaimExpert || request.value.canReassignExpert) return { href: '/help/expert-opinion.html', label: 'Инструкция по формированию заключения' }
  if (request.value.canUploadReport) return { href: '/help/report.html', label: 'Инструкция по загрузке отчёта испытаний' }
  if (request.value.canAssignExecutor || request.value.canStart || request.value.canSuspend || request.value.canResume) return { href: '/help/assignment.html', label: 'Инструкция по назначению и началу работы' }
  return null
})

function handleStartClick() {
  if (!canStartAction.value) {
    actions.startHintRevealed.value = true
    return
  }
  actions.startRequest()
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
    event.preventDefault(); closeHelpDrawer(); return
  }
  if (event.key !== 'Tab' || !helpDrawer.value) return
  const focusable = [...helpDrawer.value.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')].filter(element => !element.disabled)
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}

defineExpose({
  colorError: actions.colorError,
  colorLoading: actions.colorLoading,
  openDepartmentModal: actions.openDepartmentModal,
  setColorMark: actions.setColorMark,
})
</script>

<template>
  <ol class="process-timeline"><li v-for="(step, index) in processSteps" :key="`${index}-${step.label}`" :class="[step.state, step.tone && `tone-${step.tone}`, { terminal: step.terminal }]"><span class="process-node" :class="{ 'app-tooltip': step.state === 'current' && stateAgeLabel }" :tabindex="step.state === 'current' && stateAgeLabel ? 0 : undefined" :aria-label="step.state === 'current' && stateAgeLabel ? `${step.label}. ${stateAgeLabel}` : undefined" :aria-hidden="step.state === 'current' && stateAgeLabel ? undefined : true" :data-tooltip="step.state === 'current' ? stateAgeLabel || undefined : undefined"></span><b>{{ step.label }}</b><small>{{ step.state === 'current' ? 'Текущий статус' : step.state === 'done' ? 'Завершено' : 'Ожидается' }}</small></li></ol>
  <div v-if="hasHeroAction || actions.actionError.value" class="request-process-action" :class="{ 'request-process-action--reason': statusReason }">
    <div class="request-process-action-main"><div class="request-action-context"><span>{{ statusReason?.label || 'Следующий шаг' }}</span><b>{{ statusReason?.text || actionPrompt }}</b></div><div v-if="!statusReason" class="request-action-bar">
      <div v-if="request.canAssignExecutor" class="request-action-group"><select v-model="actions.executorChoice.value" :disabled="actions.actionLoading.value" aria-label="Исполнитель ИЦ"><option value="">Выберите исполнителя</option><option v-for="executor in actions.executors.value" :key="executor.id" :value="executor.id">{{ executor.displayName }}</option></select><button type="button" :class="request.executorId ? 'secondary' : 'primary'" :disabled="actions.actionLoading.value || !actions.executorChoice.value" @click="actions.assignExecutor">{{ actions.actionLoading.value ? 'Сохранение…' : (request.executorId ? 'Переназначить' : 'Назначить') }}</button></div>
      <div v-if="request.canStart || request.canSuspend || request.canResume" class="request-action-group"><button v-if="request.canStart" type="button" :class="[request.executorId ? 'primary' : 'secondary', { 'is-disabled': !canStartAction }]" :aria-disabled="!canStartAction" :disabled="actions.actionLoading.value" @click="handleStartClick">{{ actions.actionLoading.value ? 'Запуск…' : 'Начать работу' }}</button><button v-else-if="request.canSuspend" type="button" class="secondary" :disabled="actions.suspendResumeLoading.value" @click="actions.suspendOrResumeRequest('suspend')">{{ actions.suspendResumeLoading.value ? 'Сохранение…' : 'Приостановить' }}</button><button v-else-if="request.canResume" type="button" class="primary" :disabled="actions.suspendResumeLoading.value" @click="actions.suspendOrResumeRequest('resume')">{{ actions.suspendResumeLoading.value ? 'Сохранение…' : 'Возобновить' }}</button></div>
      <div v-if="request.canUploadReport || request.canDeleteReport" class="request-action-group"><label v-if="request.canUploadReport" class="primary upload-button">{{ documentWorkflow?.reportLoading ? 'Загрузка…' : 'Загрузить отчёт' }}<input type="file" :disabled="documentWorkflow?.reportLoading" accept=".pdf,application/pdf" @change="documentWorkflow?.uploadReport" /></label><button v-if="request.canDeleteReport" type="button" class="secondary danger" :disabled="documentWorkflow?.deleteReportLoading" @click="documentWorkflow?.deleteReport">{{ documentWorkflow?.deleteReportLoading ? 'Удаление…' : 'Удалить отчёт' }}</button></div>
      <div v-if="request.canClaimExpert" class="request-action-group"><button type="button" class="primary" :disabled="actions.claimLoading.value" @click="actions.claimExpert">{{ actions.claimLoading.value ? 'Сохранение…' : 'Взять в работу' }}</button></div>
      <div v-if="request.canPublishOpinion || request.canReassignExpert" class="request-action-group"><button v-if="request.canPublishOpinion" type="button" class="primary" :disabled="actions.opinionLoading.value" @click="actions.openOpinionModal">Написать заключение</button><select v-if="request.canReassignExpert" v-model="actions.expertChoice.value" :disabled="actions.reassignLoading.value" aria-label="Новый эксперт"><option value="">Выберите эксперта</option><option v-for="expert in actions.experts.value.filter(candidate => candidate.id !== request.expertId)" :key="expert.id" :value="expert.id">{{ expert.displayName }}</option></select><button v-if="request.canReassignExpert" type="button" class="secondary" :disabled="actions.reassignLoading.value || !actions.expertChoice.value" @click="actions.reassignExpert">{{ actions.reassignLoading.value ? 'Передача…' : 'Передать' }}</button></div>
      <div v-if="request.canSecurityDecide" class="request-action-group"><button type="button" class="primary" :disabled="actions.securityLoading.value" @click="actions.decideSecurity('approve')">{{ actions.securityLoading.value ? 'Сохранение…' : 'Согласовать' }}</button><button type="button" class="secondary" :disabled="actions.securityLoading.value" @click="actions.decideSecurity('return')">Вернуть в работу</button></div>
      <div v-if="request.canReject || request.canWithdraw" class="request-action-group request-action-group--danger"><button v-if="request.canReject" type="button" class="request-danger-action" :disabled="actions.rejectLoading.value" @click="actions.rejectRequest">{{ actions.rejectLoading.value ? 'Сохранение…' : 'Отказать' }}</button><button v-if="request.canWithdraw" type="button" class="request-danger-action" :disabled="actions.withdrawLoading.value" @click="actions.withdrawRequest">{{ actions.withdrawLoading.value ? 'Сохранение…' : 'Отозвать' }}</button></div>
    </div><button v-if="actionHelp && !statusReason" ref="helpTrigger" type="button" class="request-action-help" :aria-label="actionHelp.label" :title="actionHelp.label" @click="openHelpDrawer"><AppIcon name="help" :size="16" /></button></div>
    <p v-if="request.canStart && actions.startHintRevealed.value && startHint" class="hero-hint">{{ startHint }}</p>
    <p v-for="(error, index) in [actions.suspendResumeError.value, actions.rejectError.value, documentWorkflow?.reportError, documentWorkflow?.deleteReportError, actions.claimError.value, actions.reassignError.value, actions.securityError.value, actions.withdrawError.value, actions.actionError.value].filter(Boolean)" :key="`${index}-${error}`" class="action-error">{{ error }}</p>
  </div>

  <div v-if="showHelpDrawer && actionHelp" class="request-drawer-overlay" @click.self="closeHelpDrawer"><aside ref="helpDrawer" class="request-drawer request-help-drawer" role="dialog" aria-modal="true" aria-labelledby="help-title" @keydown="handleHelpKeydown"><header class="request-drawer-head"><div><p>Заявка №{{ request.id }}</p><h2 id="help-title">Справка</h2></div><button type="button" aria-label="Закрыть справку" @click="closeHelpDrawer"><AppIcon name="close" /></button></header><HelpArticle :src="actionHelp.href" /></aside></div>

  <AppModal :open="actions.confirmDialog.state.open" title="Подтвердите действие" title-id="request-confirm-title" description-id="request-confirm-message" size="small" alert @close="actions.confirmDialog.cancel"><p id="request-confirm-message">{{ actions.confirmDialog.state.message }}</p><label v-if="actions.confirmDialog.state.reasonField" class="confirm-reason-field"><span class="visually-hidden">Причина действия</span><textarea v-model="actions.confirmDialog.state.reasonValue" maxlength="5000" :placeholder="actions.confirmDialog.state.reasonField.placeholder"></textarea></label><template #footer><button type="button" class="secondary" @click="actions.confirmDialog.cancel">Отмена</button><button type="button" class="primary" :class="{ danger: actions.confirmDialog.state.danger }" :disabled="actions.confirmDialog.state.reasonField?.required && !actions.confirmDialog.state.reasonValue.trim()" @click="actions.confirmDialog.accept">{{ actions.confirmDialog.state.confirmLabel }}</button></template></AppModal>
  <AppModal :open="actions.showOpinionModal.value" as="form" title="Экспертное заключение" title-id="opinion-modal-title" size="large" :busy="actions.opinionLoading.value" @close="actions.showOpinionModal.value = false" @submit="actions.publishOpinion"><div class="fact-list opinion-summary"><div class="fact"><span>Объект испытаний</span><b>{{ request.product }}</b></div><div class="fact"><span>Производитель</span><b>{{ request.manufacturer || '—' }}</b></div><div class="fact"><span>Поставщик</span><b>{{ request.supplier }}</b></div><div class="fact"><span>Количество образцов</span><b>{{ request.sampleQuantity || '—' }} шт.</b></div><div class="fact wide"><span>Метод испытаний</span><b>{{ request.testMethod || '—' }}</b></div></div><label class="visually-hidden" for="request-opinion-draft">Экспертное заключение</label><textarea id="request-opinion-draft" v-model="actions.opinionDraft.value" :disabled="actions.opinionLoading.value" minlength="10" maxlength="20000" placeholder="Введите итоговое заключение по результатам испытаний"></textarea><p v-if="actions.opinionError.value" class="action-error">{{ actions.opinionError.value }}</p><template #footer><button type="button" class="secondary" :disabled="actions.opinionLoading.value" @click="actions.showOpinionModal.value = false">Отмена</button><button type="submit" class="primary" :disabled="actions.opinionLoading.value">{{ actions.opinionLoading.value ? 'Публикация…' : 'Опубликовать и передать в СБ' }}</button></template></AppModal>
  <AppModal :open="actions.showDepartmentModal.value" as="form" title="Изменить подразделение" title-id="department-modal-title" size="medium" :busy="actions.departmentLoading.value" @close="actions.showDepartmentModal.value = false" @submit="actions.changeDepartment"><label>Подразделение<input v-model.trim="actions.departmentDraft.value" :disabled="actions.departmentLoading.value" maxlength="255" required /></label><p class="placeholder-copy">Новое подразделение будет указано только в этой заявке. Изменение появится в журнале действий.</p><p v-if="actions.departmentError.value" class="action-error">{{ actions.departmentError.value }}</p><template #footer><button type="button" class="secondary" :disabled="actions.departmentLoading.value" @click="actions.showDepartmentModal.value = false">Отмена</button><button type="submit" class="primary" :disabled="actions.departmentLoading.value || !actions.departmentDraft.value.trim()">{{ actions.departmentLoading.value ? 'Сохранение…' : 'Сохранить' }}</button></template></AppModal>
</template>
