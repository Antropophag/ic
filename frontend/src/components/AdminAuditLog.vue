<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { adminApi } from '../api'
import { createLatestRequestGuard } from '../latestRequestGuard'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'

const emit = defineEmits(['open-request'])
const items = ref([]); const cursor = ref(null); const hasMore = ref(false); const loading = ref(false); const error = ref(''); const selected = ref(null)
const filters = reactive({ actorId: '', eventType: '', entityType: '', requestId: '', result: 'all', dateFrom: '', dateTo: '', limit: 50 })
const guard = createLatestRequestGuard()
async function load(more = false) {
  const token = guard.begin(true); loading.value = true; error.value = ''
  try {
    const result = await adminApi.auditEvents({ ...filters, cursor: more ? cursor.value : null })
    if (!guard.isCurrent(token, true)) return
    items.value = more ? [...items.value, ...(result.items || [])] : (result.items || [])
    cursor.value = result.nextCursor; hasMore.value = Boolean(result.hasMore)
  } catch { if (guard.isCurrent(token, true)) error.value = 'Не удалось загрузить журнал действий.' }
  finally { if (guard.isCurrent(token, true)) loading.value = false }
}
function submit() { cursor.value = null; load(false) }
function localTime(value) { return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value)) }
onMounted(() => load()); onBeforeUnmount(() => guard.invalidate())
</script>
<template>
  <section class="admin-log" aria-labelledby="admin-tab-audit">
    <form class="admin-filters" @submit.prevent="submit"><label>ID пользователя<input v-model="filters.actorId" type="number" min="1"></label><label>Тип события<input v-model.trim="filters.eventType"></label><label>Объект<select v-model="filters.entityType"><option value="">Все</option><option value="request">Заявка</option><option value="user">Пользователь</option></select></label><label>Заявка<input v-model="filters.requestId" type="number" min="1"></label><label>Результат<select v-model="filters.result"><option value="all">Все</option><option value="denied">Отклонённые</option></select></label><label>С<input v-model="filters.dateFrom" type="date"></label><label>По<input v-model="filters.dateTo" type="date"></label><button class="secondary">Применить</button></form>
    <div v-if="error" class="admin-state admin-state--error"><span>{{ error }}</span><button type="button" class="secondary" @click="load(false)">Повторить</button></div><div v-else-if="loading && !items.length" class="admin-state">Загрузка…</div>
    <div v-else-if="items.length" class="table-wrap admin-table-wrap admin-log-table"><table class="admin-table"><thead><tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Объект</th><th>Результат</th></tr></thead><tbody><tr v-for="item in items" :key="item.id" tabindex="0" :class="{ denied: item.result === 'denied' }" @click="selected = item" @keydown.enter.self="selected = item" @keydown.space.self.prevent="selected = item"><td>{{ localTime(item.createdAt) }}</td><td>{{ item.actor.displayName }}<small>{{ item.actor.adLogin }}</small></td><td>{{ item.title }}</td><td><button v-if="item.entity.requestId" type="button" class="admin-link" @click.stop="emit('open-request', item.entity.requestId)">{{ item.entity.label }}</button><span v-else>{{ item.entity.label }}</span></td><td><span class="badge" :class="item.result === 'denied' ? 'orange' : 'green'">{{ item.result === 'denied' ? 'Отклонено' : 'Успешно' }}</span></td></tr></tbody></table></div><div v-else class="admin-empty"><div class="admin-empty-icon" aria-hidden="true"><AppIcon name="search" :size="20" /></div><h3>События не найдены</h3><p>Измените параметры фильтрации.</p></div>
    <div v-if="hasMore" class="admin-more"><button type="button" class="secondary" :disabled="loading" @click="load(true)">{{ loading ? 'Загрузка…' : 'Показать ещё' }}</button></div>
    <AppModal :open="Boolean(selected)" title="Детали события" title-id="admin-audit-detail-title" size="medium" @close="selected = null"><dl v-if="selected" class="admin-details"><dt>Событие</dt><dd>{{ selected.title }}</dd><dt>Точное время</dt><dd>{{ selected.createdAt }}</dd><dt>Пользователь</dt><dd>{{ selected.actor.displayName }} ({{ selected.actor.adLogin }})</dd><dt>Тип события</dt><dd>{{ selected.eventType }}</dd><dt>Правило</dt><dd>{{ selected.ruleId }}</dd><dt>Объект</dt><dd>{{ selected.entity.label }}</dd><template v-for="(value, key) in selected.details" :key="key"><dt>{{ key }}</dt><dd>{{ value }}</dd></template></dl></AppModal>
  </section>
</template>
