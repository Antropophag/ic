<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { adminApi } from '../api'
import { createLatestRequestGuard } from '../latestRequestGuard'
const data = ref(null); const loading = ref(false); const error = ref('')
const requestGuard = createLatestRequestGuard()
let requestController
const defs = [['database', 'База данных'], ['ldap', 'LDAP'], ['smtp', 'SMTP'], ['storage', 'Файловое хранилище']]
const detailLabels = { database: { databaseName: 'База данных', databaseVersion: 'Версия СУБД' }, ldap: { endpoint: 'Сервер', domain: 'Домен', baseDn: 'Base DN', transportSecurity: 'Защита соединения' }, smtp: { endpoint: 'Сервер', transportSecurity: 'Защита соединения', sender: 'Отправитель' }, storage: { path: 'Путь', freeSpace: 'Свободно' } }
const detailValue = value => ({ 'Not configured': 'Не указано', None: 'Без TLS', Unknown: 'Не определено' })[value] || value
const services = computed(() => defs.map(([id, label]) => {
  const service = data.value?.services?.[id] || {}
  return { id, label, ...service, details: Object.entries(service.details || {}).map(([key, value]) => [detailLabels[id]?.[key] || key, detailValue(value)]) }
}))
const states = { operational: ['Работает', 'green'], error: ['Ошибка', 'red'], unavailable: ['Недоступно для проверки', 'gray'] }
const state = value => states[value] || states.unavailable
const show = value => value || 'Не указано'
const checked = value => new Intl.DateTimeFormat('ru-RU', { timeZone: 'Europe/Moscow', dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value))
async function load() { if (loading.value) return; const token = requestGuard.begin('overview'); requestController = new AbortController(); loading.value = true; error.value = ''; try { const response = await adminApi.systemOverview(requestController.signal); if (requestGuard.isCurrent(token, 'overview')) data.value = response } catch { if (requestGuard.isCurrent(token, 'overview')) error.value = 'Не удалось получить состояние системы.' } finally { if (requestGuard.isCurrent(token, 'overview')) loading.value = false } }
onMounted(load)
onBeforeUnmount(() => { requestGuard.invalidate(); requestController?.abort() })
</script>
<template><section class="admin-overview" aria-labelledby="overview-title"><header class="admin-overview-head"><div><h3 id="overview-title">Состояние системы</h3><p v-if="data">Проверено: <time :datetime="data.checkedAt">{{ checked(data.checkedAt) }}</time></p><p v-else>Фактическая конфигурация приложения и доступность зависимостей</p></div><button type="button" class="secondary" :disabled="loading" @click="load">{{ loading ? 'Обновление…' : 'Обновить' }}</button></header><div v-if="error" class="admin-state admin-state--error" role="alert"><span>{{ error }}<template v-if="data"> Показаны последние полученные данные.</template></span><button v-if="!data" type="button" class="secondary" @click="load">Повторить</button></div><div class="admin-overview-grid" :aria-busy="loading"><section class="admin-overview-block"><h4>Приложение</h4><dl v-if="data" class="admin-overview-facts"><div><dt>Название</dt><dd>{{ data.application.name }}</dd></div><div><dt>Версия</dt><dd>{{ show(data.application.version) }}</dd></div><div><dt>Commit</dt><dd>{{ show(data.application.commitSha) }}</dd></div><div><dt>Время сборки</dt><dd>{{ show(data.application.builtAt) }}</dd></div></dl><div v-else-if="loading" class="admin-overview-skeleton" aria-label="Загрузка"><span v-for="n in 4" :key="n" /></div><p v-else class="admin-overview-placeholder">Данные не получены</p></section><section class="admin-overview-block"><h4>Зависимости</h4><div v-if="data" class="admin-service-list"><article v-for="service in services" :key="service.id" class="admin-service-row"><div class="admin-service-head"><div><strong>{{ service.label }}</strong><small>{{ service.message }}</small></div><span class="badge" :class="state(service.status)[1]">{{ state(service.status)[0] }}</span></div><dl class="admin-service-details"><div v-for="detail in service.details" :key="detail[0]"><dt>{{ detail[0] }}</dt><dd>{{ detail[1] }}</dd></div></dl></article></div><div v-else-if="loading" class="admin-overview-skeleton" aria-label="Загрузка"><span v-for="n in 4" :key="n" /></div><p v-else class="admin-overview-placeholder">Данные не получены</p></section></div></section></template>
