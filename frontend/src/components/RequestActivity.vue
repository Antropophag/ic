<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { requestApi } from '../api'
import { createLatestRequestGuard } from '../latestRequestGuard'
import { avatarRoleClass, canSubmitComment, commentFromApi, documentKind, initialsFor, newestFirstFeed } from '../registry'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  request: { type: Object, required: true },
  currentInitials: { type: String, default: '' },
  detailLoading: { type: Boolean, default: false },
  refresh: { type: Function, required: true },
  documentWorkflow: { type: Object, default: null },
})
const emit = defineEmits(['comments-added', 'older-comments-loaded'])

const commentDraft = ref('')
const commentLoading = ref(false)
const commentError = ref('')
const olderCommentsLoading = ref(false)
const showAuditDrawer = ref(false)
const auditTrigger = ref(null)
const auditDrawer = ref(null)
const commentRequestGuard = createLatestRequestGuard()
const commentsPageRequestGuard = createLatestRequestGuard()
const feed = computed(() => newestFirstFeed(props.request.history || [], props.request.comments || []))

function avatarClassForAuthor(author) {
  if (author && author === props.request.expert) return avatarRoleClass('expert')
  if (author && author === props.request.executor) return avatarRoleClass('ic_executor')
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

function openAuditDrawer() {
  showAuditDrawer.value = true
  nextTick(() => auditDrawer.value?.querySelector('button')?.focus())
}

function closeAuditDrawer() {
  showAuditDrawer.value = false
  nextTick(() => auditTrigger.value?.focus())
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
    event.preventDefault(); last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault(); first.focus()
  }
}

async function addComment() {
  if (!commentDraft.value.trim()) {
    commentError.value = 'Введите текст комментария.'
    return
  }
  const requestId = props.request.backendId
  const token = commentRequestGuard.begin(requestId)
  commentLoading.value = true
  commentError.value = ''
  try {
    const result = await requestApi.addComment(requestId, commentDraft.value)
    if (!commentRequestGuard.isCurrent(token, props.request.backendId)) return
    emit('comments-added', commentFromApi(result))
    commentDraft.value = ''
  } catch (error) {
    if (!commentRequestGuard.isCurrent(token, props.request.backendId)) return
    if (error.status === 422) commentError.value = 'Введите комментарий длиной не более 10 000 символов.'
    else if (error.status === 409) {
      await props.refresh(requestId)
      commentError.value = 'На текущем этапе добавлять комментарии нельзя.'
    }
    else commentError.value = 'Не удалось добавить комментарий.'
  } finally {
    if (commentRequestGuard.isCurrent(token, props.request.backendId)) commentLoading.value = false
  }
}

async function loadOlderComments() {
  const requestId = props.request.backendId
  const beforeId = props.request.commentsPage?.nextBeforeId
  if (!beforeId) return
  const token = commentsPageRequestGuard.begin(requestId)
  olderCommentsLoading.value = true
  commentError.value = ''
  try {
    const result = await requestApi.comments(requestId, beforeId)
    if (!commentsPageRequestGuard.isCurrent(token, props.request.backendId)) return
    emit('older-comments-loaded', {
      items: result.items.map(commentFromApi),
      page: { hasMore: result.hasMore, nextBeforeId: result.nextBeforeId },
    })
  } catch {
    if (commentsPageRequestGuard.isCurrent(token, props.request.backendId)) commentError.value = 'Не удалось загрузить предыдущие комментарии.'
  } finally {
    if (commentsPageRequestGuard.isCurrent(token, props.request.backendId)) olderCommentsLoading.value = false
  }
}

function invalidate() {
  for (const guard of [commentRequestGuard, commentsPageRequestGuard]) guard.invalidate()
  commentLoading.value = false
  olderCommentsLoading.value = false
  showAuditDrawer.value = false
}

watch(() => props.request.backendId, () => {
  invalidate()
  commentDraft.value = ''
  commentError.value = ''
})
onBeforeUnmount(invalidate)
</script>

<template>
  <section class="card process-section request-process" aria-labelledby="process-title">
    <div class="section-title"><h3 id="process-title">Процесс заявки</h3><button ref="auditTrigger" type="button" class="request-text-button" @click="openAuditDrawer">Подробная история</button></div>
    <slot name="process" />
  </section>
  <article id="request-comments" class="card feed request-comments">
    <div class="section-title"><h3>Лента</h3></div>
    <p v-if="commentError" class="action-error">{{ commentError }}</p>
    <form v-if="canSubmitComment(request, detailLoading)" class="comment-input request-comment-composer" @submit.prevent="addComment"><span class="avatar small">{{ currentInitials }}</span><input v-model="commentDraft" :disabled="commentLoading" maxlength="10000" placeholder="Оставьте комментарий…" /><button :disabled="commentLoading" aria-label="Отправить комментарий"><AppIcon name="send" :size="16" /></button></form>
    <p v-else class="placeholder-copy request-comment-unavailable">На текущем этапе добавлять комментарии нельзя.</p>
    <div class="stream">
      <div v-for="entry in feed" :key="`${entry.type}-${entry.id}`" class="entry" :class="{ system: entry.type !== 'comment' }">
        <span v-if="entry.type === 'comment'" class="avatar small" :class="avatarClassForAuthor(entry.author)">{{ initialsFor(entry.author) }}</span>
        <span v-else class="request-feed-event-actor" aria-hidden="true"><span class="avatar small request-feed-system-avatar">{{ initialsFor(entry.actor) }}</span><span class="request-feed-event-mark" :class="eventIconTone(entry.action)"><AppIcon :name="eventIcon(entry.action)" :size="10" /></span></span>
        <div class="entry-body"><div class="entry-head"><b>{{ entry.type === 'comment' ? entry.author : entry.actor }}</b><time>{{ entry.type === 'comment' ? entry.createdAt : entry.occurredAt }}</time></div><p>{{ entry.type === 'comment' ? entry.body : entry.description }}</p><div v-if="entry.versionId && entry.originalName" class="request-audit-file request-feed-file"><button type="button" class="request-audit-file-open app-tooltip" data-tooltip="Открыть документ" :aria-label="`Открыть ${entry.originalName}`" @click="documentWorkflow?.openDocument(entry)"><span class="request-file-thumb request-audit-file-thumb" aria-hidden="true"><span class="request-file-lines"></span><span class="request-file-type" :class="fileTypeClassFor(entry)">{{ fileExtensionFor(entry) }}</span></span><span><b :title="entry.originalName">{{ entry.originalName }}</b><small>Открыть вложение</small></span></button><button type="button" class="request-file-action app-tooltip" data-tooltip="Скачать документ" :aria-label="`Скачать ${entry.originalName}`" @click.stop="documentWorkflow?.downloadDocument(entry)"><AppIcon name="download" :size="14" /></button></div></div>
      </div>
    </div>
    <p v-if="!feed.length" class="placeholder-copy">Событий пока нет.</p>
    <button v-if="request.commentsPage?.hasMore" class="secondary" :disabled="olderCommentsLoading" @click="loadOlderComments">{{ olderCommentsLoading ? 'Загрузка…' : 'Показать ранние комментарии' }}</button>
  </article>
  <div v-if="showAuditDrawer" class="request-drawer-overlay" @click.self="closeAuditDrawer">
    <aside ref="auditDrawer" class="request-drawer" role="dialog" aria-modal="true" aria-labelledby="audit-title" @keydown="handleAuditKeydown">
      <header class="request-drawer-head"><div><p>Заявка №{{ request.id }}</p><h2 id="audit-title">История процесса</h2></div><button type="button" aria-label="Закрыть историю" @click="closeAuditDrawer"><AppIcon name="close" /></button></header>
      <div class="request-drawer-body"><div v-for="entry in request.history || []" :key="entry.id" class="request-audit-entry"><span class="request-audit-node" aria-hidden="true"></span><div><b>{{ entry.actor }}</b><p>{{ entry.description }}</p><time>{{ entry.occurredAt }}</time><div v-if="entry.versionId && entry.originalName" class="request-audit-file"><button type="button" class="request-audit-file-open app-tooltip" data-tooltip="Открыть документ" :aria-label="`Открыть ${entry.originalName}`" @click="documentWorkflow?.openDocument(entry)"><span class="request-file-thumb request-audit-file-thumb" aria-hidden="true"><span class="request-file-lines"></span><span class="request-file-type" :class="fileTypeClassFor(entry)">{{ fileExtensionFor(entry) }}</span></span><span><b :title="entry.originalName">{{ entry.originalName }}</b><small>Открыть вложение</small></span></button><button type="button" class="request-file-action app-tooltip" data-tooltip="Скачать документ" :aria-label="`Скачать ${entry.originalName}`" @click.stop="documentWorkflow?.downloadDocument(entry)"><AppIcon name="download" :size="14" /></button></div></div></div><p v-if="!request.history?.length" class="placeholder-copy">История процесса пока пуста.</p></div>
    </aside>
  </div>
</template>
