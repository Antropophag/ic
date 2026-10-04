import { onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import { createConfirmDialog } from '../confirmDialog'
import { confirmRequestAction } from '../confirmRequestAction'
import { createLatestRequestGuard } from '../latestRequestGuard'

export function useRequestActions(request, refresh) {
  const actionError = ref('')
  const actionLoading = ref(false)
  const executors = ref([])
  const executorChoice = ref('')
  const experts = ref([])
  const expertChoice = ref('')
  const opinionDraft = ref('')
  const opinionLoading = ref(false)
  const opinionError = ref('')
  const showOpinionModal = ref(false)
  const departmentDraft = ref('')
  const departmentLoading = ref(false)
  const departmentError = ref('')
  const showDepartmentModal = ref(false)
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
  const suspendResumeLoading = ref(false)
  const suspendResumeError = ref('')
  const startHintRevealed = ref(false)
  const confirmDialog = createConfirmDialog()
  const guards = Object.fromEntries([
    'action', 'executors', 'experts', 'opinion', 'department', 'security', 'color', 'reject',
    'withdraw', 'claim', 'reassign', 'suspendResume',
  ].map(name => [name, createLatestRequestGuard()]))

  async function recoverConflict(requestId, message) {
    try {
      await refresh(requestId, { suppressStaleActions: true })
      actionError.value = `${message} Данные обновлены — проверьте актуальный статус.`
    } catch {
      actionError.value = `${message} Не удалось обновить данные. Обновите страницу перед следующим действием.`
    }
  }

  function errorMessage(error, messages, fallback) {
    return messages[error.status] || fallback
  }

  async function loadExecutors() {
    const requestId = request.value?.backendId
    const token = guards.executors.begin(requestId)
    try {
      const result = await requestApi.executors()
      if (guards.executors.isCurrent(token, request.value?.backendId)) executors.value = result.items
    } catch {
      if (guards.executors.isCurrent(token, request.value?.backendId)) actionError.value = 'Не удалось загрузить список исполнителей.'
    }
  }

  async function loadExperts() {
    const requestId = request.value?.backendId
    const token = guards.experts.begin(requestId)
    try {
      const result = await requestApi.experts()
      if (guards.experts.isCurrent(token, request.value?.backendId)) experts.value = result.items
    } catch {
      if (guards.experts.isCurrent(token, request.value?.backendId)) actionError.value = 'Не удалось загрузить список экспертов.'
    }
  }

  async function assignExecutor() {
    if (!executorChoice.value) {
      actionError.value = 'Выберите исполнителя.'
      return
    }
    const requestId = request.value.backendId
    const lockVersion = request.value.lockVersion
    if (!(await confirmDialog.ask('Назначить выбранного сотрудника исполнителем?', { confirmLabel: 'Назначить' }))) return
    if (request.value?.backendId !== requestId) return
    const token = guards.action.begin(requestId)
    actionLoading.value = true
    actionError.value = ''
    try {
      await requestApi.assignExecutor(requestId, Number(executorChoice.value), lockVersion)
      if (!guards.action.isCurrent(token, request.value?.backendId)) return
      try { await refresh(requestId) } catch { actionError.value = 'Исполнитель назначен, но данные на экране не обновились. Обновите страницу перед следующим действием.' }
    } catch (error) {
      if (!guards.action.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else actionError.value = error.status === 403 ? 'У вас нет права назначать исполнителя.' : 'Не удалось назначить исполнителя. Обновите страницу и повторите попытку.'
    } finally {
      if (guards.action.isCurrent(token, request.value?.backendId)) actionLoading.value = false
    }
  }

  async function startRequest() {
    const requestId = request.value.backendId
    const lockVersion = request.value.lockVersion
    if (!(await confirmDialog.ask('Начать работу по заявке?', { confirmLabel: 'Начать работу' }))) return
    if (request.value?.backendId !== requestId) return
    const token = guards.action.begin(requestId)
    actionLoading.value = true
    actionError.value = ''
    try {
      await requestApi.start(requestId, lockVersion)
      if (!guards.action.isCurrent(token, request.value?.backendId)) return
      startHintRevealed.value = false
      try { await refresh(requestId) } catch { actionError.value = 'Заявка переведена в работу, но данные на экране не обновились. Обновите страницу перед следующим действием.' }
    } catch (error) {
      if (!guards.action.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else actionError.value = error.status === 403 ? 'У вас нет права переводить эту заявку в работу.' : 'Не удалось перевести заявку в работу. Повторите попытку.'
    } finally {
      if (guards.action.isCurrent(token, request.value?.backendId)) actionLoading.value = false
    }
  }

  async function suspendOrResumeRequest(action) {
    if (suspendResumeLoading.value) return
    const isSuspend = action === 'suspend'
    const confirmationMessage = isSuspend ? 'Приостановить работу по заявке?' : 'Возобновить работу по заявке?'
    const confirmationOptions = {
      confirmLabel: isSuspend ? 'Приостановить' : 'Возобновить',
      reasonField: isSuspend ? { required: true, placeholder: 'Опишите причину приостановки' } : null,
    }
    const context = await confirmRequestAction(
      () => request.value,
      () => confirmDialog.ask(confirmationMessage, confirmationOptions),
    )
    if (!context) return
    const { requestId, lockVersion, confirmed } = context
    const token = guards.suspendResume.begin(requestId)
    suspendResumeLoading.value = true
    suspendResumeError.value = ''
    try {
      const mutation = isSuspend
        ? requestApi.suspend(requestId, lockVersion, confirmed.reason)
        : requestApi.resume(requestId, lockVersion)
      await mutation
      if (!guards.suspendResume.isCurrent(token, request.value?.backendId)) return
      const refreshError = isSuspend
        ? 'Работа приостановлена, но данные на экране не обновились. Обновите страницу перед следующим действием.'
        : 'Работа возобновлена, но данные на экране не обновились. Обновите страницу перед следующим действием.'
      try { await refresh(requestId) } catch { suspendResumeError.value = refreshError }
    } catch (error) {
      if (!guards.suspendResume.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else suspendResumeError.value = errorMessage(error, {
        403: 'Приостановить или возобновить работу может только назначенный исполнитель или руководитель.',
      }, isSuspend ? 'Не удалось приостановить работу. Повторите попытку.' : 'Не удалось возобновить работу. Повторите попытку.')
    } finally {
      if (guards.suspendResume.isCurrent(token, request.value?.backendId)) suspendResumeLoading.value = false
    }
  }

  async function claimExpert() {
    if (claimLoading.value) return
    const requestId = request.value.backendId
    const token = guards.claim.begin(requestId)
    claimLoading.value = true
    claimError.value = ''
    try {
      await requestApi.claimExpert(requestId, request.value.lockVersion)
      if (!guards.claim.isCurrent(token, request.value?.backendId)) return
      try { await refresh(requestId) } catch { claimError.value = 'Заявка принята в работу, но данные на экране не обновились. Обновите страницу перед следующим действием.' }
    } catch (error) {
      if (!guards.claim.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else claimError.value = error.status === 403 ? 'У вас нет права брать эту заявку в работу.' : 'Не удалось взять заявку в работу. Обновите страницу и повторите попытку.'
    } finally {
      if (guards.claim.isCurrent(token, request.value?.backendId)) claimLoading.value = false
    }
  }

  async function reassignExpert() {
    if (reassignLoading.value) return
    if (!expertChoice.value) {
      reassignError.value = 'Выберите эксперта.'
      return
    }
    const context = await confirmRequestAction(
      () => request.value,
      () => confirmDialog.ask('Передать заявку выбранному эксперту?', { confirmLabel: 'Передать' }),
    )
    if (!context) return
    const { requestId, lockVersion } = context
    const token = guards.reassign.begin(requestId)
    reassignLoading.value = true
    reassignError.value = ''
    try {
      await requestApi.reassignExpert(requestId, Number(expertChoice.value), lockVersion)
      if (!guards.reassign.isCurrent(token, request.value?.backendId)) return
      expertChoice.value = ''
      try { await refresh(requestId) } catch { reassignError.value = 'Заявка переназначена, но данные на экране не обновились. Обновите страницу перед следующим действием.' }
    } catch (error) {
      if (!guards.reassign.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else reassignError.value = error.status === 403 ? 'У вас нет права переназначать эту заявку.' : 'Не удалось переназначить заявку. Обновите страницу и повторите попытку.'
    } finally {
      if (guards.reassign.isCurrent(token, request.value?.backendId)) reassignLoading.value = false
    }
  }

  async function rejectRequest() {
    if (rejectLoading.value) return
    const requestId = request.value.backendId
    const lockVersion = request.value.lockVersion
    const confirmed = await confirmDialog.ask('Отказать в проведении испытаний?', { confirmLabel: 'Отказать', danger: true, reasonField: { required: true, placeholder: 'Опишите причину отказа' } })
    if (!confirmed || request.value?.backendId !== requestId) return
    const token = guards.reject.begin(requestId)
    rejectLoading.value = true
    rejectError.value = ''
    try {
      await requestApi.reject(requestId, lockVersion, confirmed.reason)
      if (!guards.reject.isCurrent(token, request.value?.backendId)) return
      try { await refresh(requestId) } catch { rejectError.value = 'Отказ оформлен, но данные на экране не обновились.' }
    } catch (error) {
      if (!guards.reject.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else rejectError.value = error.status === 403 ? 'Отказать в проведении испытаний может только руководитель.' : 'Не удалось сохранить отказ. Повторите попытку.'
    } finally {
      if (guards.reject.isCurrent(token, request.value?.backendId)) rejectLoading.value = false
    }
  }

  async function withdrawRequest() {
    if (withdrawLoading.value) return
    const requestId = request.value.backendId
    const lockVersion = request.value.lockVersion
    const confirmed = await confirmDialog.ask('Отозвать эту заявку?', { confirmLabel: 'Отозвать', danger: true, reasonField: { required: true, placeholder: 'Опишите причину отзыва' } })
    if (!confirmed || request.value?.backendId !== requestId) return
    const token = guards.withdraw.begin(requestId)
    withdrawLoading.value = true
    withdrawError.value = ''
    try {
      await requestApi.withdraw(requestId, lockVersion, confirmed.reason)
      if (!guards.withdraw.isCurrent(token, request.value?.backendId)) return
      try { await refresh(requestId) } catch { withdrawError.value = 'Заявка отозвана, но обновить карточку не удалось.' }
    } catch (error) {
      if (!guards.withdraw.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else withdrawError.value = error.status === 403 ? 'Отозвать заявку может только инициатор.' : 'Не удалось отозвать заявку. Повторите попытку.'
    } finally {
      if (guards.withdraw.isCurrent(token, request.value?.backendId)) withdrawLoading.value = false
    }
  }

  async function decideSecurity(decision) {
    const isApprove = decision === 'approve'
    const confirmationMessage = isApprove ? 'Согласовать заключение и завершить заявку?' : 'Вернуть заявку исполнителю на доработку?'
    const confirmationOptions = isApprove
      ? { confirmLabel: 'Согласовать' }
      : { confirmLabel: 'Вернуть', reasonField: { required: true, placeholder: 'Опишите, что нужно исправить' } }
    const context = await confirmRequestAction(
      () => request.value,
      () => confirmDialog.ask(confirmationMessage, confirmationOptions),
    )
    if (!context) return
    const { requestId, lockVersion, confirmed } = context
    const token = guards.security.begin(requestId)
    securityLoading.value = true
    securityError.value = ''
    try {
      const reason = isApprove ? null : confirmed.reason
      await requestApi.decideSecurity(requestId, decision, reason, lockVersion)
      if (!guards.security.isCurrent(token, request.value?.backendId)) return
      try { await refresh(requestId, { disableCapabilities: ['canSecurityDecide'] }) } catch { actionError.value = 'Решение сохранено, но данные на экране не обновились. Обновите страницу перед следующим действием.' }
    } catch (error) {
      if (!guards.security.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else securityError.value = errorMessage(error, {
        403: 'Решение может принять только сотрудник СБ на этапе контроля.',
        422: 'Проверьте решение и причину возврата.',
      }, 'Не удалось сохранить решение СБ.')
    } finally {
      if (guards.security.isCurrent(token, request.value?.backendId)) securityLoading.value = false
    }
  }

  function openOpinionModal() {
    opinionError.value = ''
    showOpinionModal.value = true
  }

  async function publishOpinion() {
    const body = opinionDraft.value.trim()
    if (body.length < 10) {
      opinionError.value = 'Заключение должно содержать не менее 10 символов.'
      return
    }
    const requestId = request.value.backendId
    const token = guards.opinion.begin(requestId)
    opinionLoading.value = true
    opinionError.value = ''
    try {
      await requestApi.publishOpinion(requestId, body, request.value.lockVersion)
      if (!guards.opinion.isCurrent(token, request.value?.backendId)) return
      opinionDraft.value = ''
      showOpinionModal.value = false
      try { await refresh(requestId, { disableCapabilities: ['canPublishOpinion'] }) } catch { actionError.value = 'Заключение опубликовано, но обновить карточку не удалось. Не отправляйте его повторно.' }
    } catch (error) {
      if (!guards.opinion.isCurrent(token, request.value?.backendId)) return
      if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
      else opinionError.value = errorMessage(error, {
        403: 'Опубликовать заключение может только назначенный эксперт.',
        422: 'Заключение должно содержать от 10 до 20 000 символов.',
      }, 'Не удалось опубликовать заключение.')
    } finally {
      if (guards.opinion.isCurrent(token, request.value?.backendId)) opinionLoading.value = false
    }
  }

  async function setColorMark(color) {
    if (colorLoading.value || color === request.value.color) return
    const requestId = request.value.backendId
    const token = guards.color.begin(requestId)
    colorLoading.value = true
    colorError.value = ''
    try {
      try {
        await requestApi.setColor(requestId, color, request.value.lockVersion)
      } catch (error) {
        if (!guards.color.isCurrent(token, request.value?.backendId)) return
        if (error.status === 409) await recoverConflict(requestId, 'Заявка уже изменена.')
        else colorError.value = error.status === 403 ? 'У вас нет права менять цвет заявки.' : 'Не удалось сохранить цвет. Повторите попытку.'
        return false
      }
      if (!guards.color.isCurrent(token, request.value?.backendId)) return
      try {
        await refresh(requestId)
      } catch {
        if (guards.color.isCurrent(token, request.value?.backendId)) colorError.value = 'Цвет сохранён, но данные на экране не обновились.'
      }
      return true
    } finally {
      if (guards.color.isCurrent(token, request.value?.backendId)) colorLoading.value = false
    }
  }

  function openDepartmentModal() {
    departmentDraft.value = request.value.department === 'Подразделение не указано' ? '' : request.value.department
    departmentError.value = ''
    showDepartmentModal.value = true
  }

  async function changeDepartment() {
    const department = departmentDraft.value.trim()
    if (!department) return
    const requestId = request.value.backendId
    const token = guards.department.begin(requestId)
    departmentLoading.value = true
    departmentError.value = ''
    try {
      try {
        await requestApi.changeDepartment(requestId, department, request.value.lockVersion)
      } catch (error) {
        if (!guards.department.isCurrent(token, request.value?.backendId)) return
        if (error.status === 409) {
          showDepartmentModal.value = false
          await recoverConflict(requestId, 'Заявка уже изменена.')
        } else departmentError.value = error.payload?.errors?.department?.[0] || error.message || 'Не удалось изменить подразделение.'
        return
      }
      if (!guards.department.isCurrent(token, request.value?.backendId)) return
      showDepartmentModal.value = false
      try {
        await refresh(requestId, { emitUpdated: true })
      } catch (error) {
        if (guards.department.isCurrent(token, request.value?.backendId)) departmentError.value = error.payload?.errors?.department?.[0] || error.message || 'Не удалось изменить подразделение.'
      }
    } finally {
      if (guards.department.isCurrent(token, request.value?.backendId)) departmentLoading.value = false
    }
  }

  function reset() {
    Object.values(guards).forEach(guard => guard.invalidate())
    confirmDialog.cancel()
    executorChoice.value = request.value?.executorId || ''
    expertChoice.value = ''
    opinionDraft.value = ''
    showOpinionModal.value = false
    showDepartmentModal.value = false
    startHintRevealed.value = false
    for (const error of [actionError, opinionError, departmentError, securityError, colorError, rejectError, withdrawError, claimError, reassignError, suspendResumeError]) error.value = ''
    for (const loading of [actionLoading, opinionLoading, departmentLoading, securityLoading, colorLoading, rejectLoading, withdrawLoading, claimLoading, reassignLoading, suspendResumeLoading]) loading.value = false
  }

  watch(() => request.value?.backendId, () => {
    reset()
    if (request.value?.canAssignExecutor && !executors.value.length) loadExecutors()
    if (request.value?.canReassignExpert && !experts.value.length) loadExperts()
  }, { immediate: true })
  watch(() => [request.value?.canAssignExecutor, request.value?.canReassignExpert], () => {
    executorChoice.value = request.value?.executorId || ''
    if (request.value?.canAssignExecutor && !executors.value.length) loadExecutors()
    if (request.value?.canReassignExpert && !experts.value.length) loadExperts()
  })
  onBeforeUnmount(reset)

  return {
    actionError, actionLoading, changeDepartment, claimError, claimExpert, claimLoading, colorError, colorLoading,
    confirmDialog, decideSecurity, departmentDraft, departmentError,
    departmentLoading, executorChoice, executors, expertChoice, experts, openDepartmentModal, openOpinionModal, opinionDraft,
    opinionError, opinionLoading, publishOpinion, reassignError, reassignExpert, reassignLoading, rejectError, rejectLoading,
    rejectRequest, securityError, securityLoading, setColorMark, showDepartmentModal,
    showOpinionModal, startHintRevealed, startRequest, suspendOrResumeRequest, suspendResumeError, suspendResumeLoading,
    withdrawError, withdrawLoading, withdrawRequest, assignExecutor,
  }
}
