<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import { createLatestRequestGuard } from '../latestRequestGuard'
import { REQUEST_COLORS, avatarRoleClass, fromApi, historyFromApi, commentFromApi, documentFromApi, initialsFor, withoutStaleActions } from '../registry'
import AppIcon from './AppIcon.vue'
import RequestActions from './RequestActions.vue'
import RequestActivity from './RequestActivity.vue'
import RequestDocuments from './RequestDocuments.vue'

const props = defineProps({ requestId: { type: Number, required: true }, currentInitials: { type: String, default: '' }, currentUserRoles: { type: Array, default: () => [] }, aiEnabled: { type: Boolean, default: false }, initialWarning: { type: String, default: '' } })
const emit = defineEmits(['loaded', 'unavailable', 'updated', 'close'])
const selected = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const actions = ref(null)
const documents = ref(null)
const detailRequestGuard = createLatestRequestGuard()
const hasRequestContent = computed(() => Boolean(selected.value?.product))
const participants = computed(() => {
  if (!selected.value) return []
  return [
    { name: selected.value.initiator, role: 'Инициатор', roleCode: 'employee' },
    selected.value.executorId ? { name: selected.value.executor, role: 'Исполнитель ИЦ', roleCode: 'ic_executor' } : null,
    selected.value.expertId ? { name: selected.value.expert, role: 'Эксперт', roleCode: 'expert' } : null,
  ].filter(Boolean)
})
const COLOR_LABELS = { white: 'Без цвета', red: 'Красный', orange: 'Оранжевый', blue: 'Синий', violet: 'Фиолетовый', green: 'Зелёный' }
const colorLabel = color => COLOR_LABELS[color] || color

async function loadRequestDetails(item, { rethrow = false } = {}) {
  const token = detailRequestGuard.begin(item.backendId)
  selected.value = item
  detailError.value = ''
  detailLoading.value = true
  try {
    const result = await requestApi.get(item.backendId)
    if (!detailRequestGuard.isCurrent(token, selected.value?.backendId)) return
    selected.value = {
      ...fromApi(result.item),
      history: result.history.map(historyFromApi),
      comments: result.comments.map(commentFromApi),
      commentsPage: result.commentsPage,
      documents: result.documents.map(documentFromApi),
    }
    emit('loaded', selected.value)
  } catch (error) {
    if (!detailRequestGuard.isCurrent(token, selected.value?.backendId)) return
    detailError.value = error.status === 404 ? 'Заявка не найдена или недоступна.' : 'Не удалось загрузить актуальные данные заявки.'
    if (rethrow) throw error
  } finally {
    if (detailRequestGuard.isCurrent(token, selected.value?.backendId)) detailLoading.value = false
  }
}

async function refreshSelected(requestId, options = {}) {
  if (selected.value?.backendId !== requestId) return
  if (options.suppressStaleActions) selected.value = withoutStaleActions(selected.value)
  if (options.disableCapabilities) {
    selected.value = Object.fromEntries(Object.entries(selected.value).map(([key, value]) => [key, options.disableCapabilities.includes(key) ? false : value]))
  }
  await loadRequestDetails(selected.value, { rethrow: true })
  if (options.emitUpdated) emit('updated')
}

async function setColorMark(color, event) {
  const colorControl = event.currentTarget.closest('details')
  if (await actions.value?.setColorMark(color)) colorControl?.removeAttribute('open')
}

function addComment(comment) {
  detailRequestGuard.invalidate()
  detailLoading.value = false
  selected.value = { ...selected.value, comments: [...(selected.value.comments || []), comment] }
}

function addOlderComments({ items, page }) {
  selected.value = { ...selected.value, comments: [...items, ...(selected.value.comments || [])], commentsPage: page }
}

watch(() => props.requestId, requestId => {
  detailRequestGuard.invalidate()
  selected.value = { backendId: requestId }
  loadRequestDetails(selected.value)
}, { immediate: true })
onBeforeUnmount(() => detailRequestGuard.invalidate())
</script>

<template>
  <section class="page request-page screen-panel" :class="{ 'screen-panel--active': hasRequestContent }">
    <p v-if="detailLoading" class="detail-state">Загрузка данных заявки…</p>
    <p v-if="detailError" class="detail-state error">{{ detailError }}</p>
    <article v-if="hasRequestContent && !detailError" class="card object-band request-entity-head">
      <button type="button" class="request-corner-back" :class="`request-corner-${selected.color}`" aria-label="Вернуться к списку заявок" @click="emit('close')"><svg class="request-corner-shape" viewBox="0 0 52 52" aria-hidden="true"><path d="M15 1h31.5c4 0 5.9 4.8 3.1 7.6l-41 41C5.8 52.4 1 50.5 1 46.5V15C1 7.3 7.3 1 15 1Z" /><path class="request-corner-edge" d="M49.6 8.6 8.6 49.6" /></svg><AppIcon class="request-corner-arrow" name="arrow-left" :size="16" /></button>
      <div class="object-status-row"><span class="badge request-status" :class="selected.tone">{{ selected.status }}</span><details v-if="selected.canSetColor" class="request-color-control"><summary><span class="request-color-dot" :class="selected.color" aria-hidden="true"></span>Цветовая метка</summary><div class="request-color-menu" role="group" aria-label="Цветовая метка заявки в реестре"><button v-for="color in REQUEST_COLORS" :key="color" type="button" :class="{ active: selected.color === color }" :disabled="actions?.colorLoading" @click="setColorMark(color, $event)"><span>{{ colorLabel(color) }}</span><span class="request-color-dot" :class="color" aria-hidden="true"></span></button></div></details></div>
      <p v-if="actions?.colorError" class="action-error">{{ actions.colorError }}</p>
      <h2 class="object-title">{{ selected.product }}</h2><p class="request-entity-context">{{ selected.manufacturer || 'Производитель не указан' }} · {{ selected.sampleQuantity || '—' }} шт.</p>
      <section id="request-overview" class="request-overview" aria-labelledby="overview-title"><h3 id="overview-title" class="visually-hidden">Ключевые сведения</h3><div class="facts-row"><div class="fact"><span>Подразделение</span><b>{{ selected.department }}</b><button v-if="selected.canEditDepartment" type="button" class="secondary" @click="actions?.openDepartmentModal">Изменить</button></div><div class="fact"><span>Производитель</span><b>{{ selected.manufacturer || '—' }}</b></div><div class="fact"><span>Поставщик</span><b>{{ selected.supplier }}</b></div><div class="fact"><span>Количество образцов</span><b>{{ selected.sampleQuantity || '—' }} шт.</b></div></div><div class="method-row"><span>Метод испытаний</span><p>{{ selected.testMethod || '—' }}</p></div></section>
    </article>
    <div v-if="hasRequestContent && !detailError" class="request-grid">
      <div class="stack">
        <RequestActivity :request="selected" :current-initials="currentInitials" :detail-loading="detailLoading" :refresh="refreshSelected" :document-workflow="documents" @comments-added="addComment" @older-comments-loaded="addOlderComments">
          <template #process><RequestActions ref="actions" :request="selected" :refresh="refreshSelected" :document-workflow="documents" :current-user-roles="currentUserRoles" :initial-warning="initialWarning" /></template>
        </RequestActivity>
      </div>
      <aside class="stack side-column">
        <article class="card request-participants"><h3>Участники</h3><div v-for="person in participants" :key="`${person.role}-${person.name}`" class="request-person-row"><span class="avatar small" :class="avatarRoleClass(person.roleCode)">{{ initialsFor(person.name) }}</span><span><b>{{ person.name }}</b><small>{{ person.role }}</small></span></div><section class="request-security-section" aria-labelledby="security-control-title"><h3 id="security-control-title">Контроль СБ</h3><div class="request-security-status"><span class="security-mark-icon" :class="selected.securityMarkDisplay?.className" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path :d="selected.securityMarkDisplay?.path" /></svg></span><span><b>{{ selected.securityMarkDisplay?.label }}</b><small>Статус проверки</small></span></div></section></article>
        <RequestDocuments ref="documents" :request="selected" :refresh="refreshSelected" :ai-enabled="aiEnabled" />
      </aside>
    </div>
  </section>
</template>
