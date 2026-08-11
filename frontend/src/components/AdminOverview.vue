/home/antropophag/.profile: line 43: syntax error near unexpected token `fi'
/home/antropophag/.profile: line 43: `fi'
<script setup>
import { computed, onMounted, ref } from 'vue'
import { adminApi } from '../api'
const data = ref(null); const loading = ref(false); const error = ref('')
const defs = [['database', 'База данных'], ['ldap', 'LDAP'], ['smtp', 'SMTP'], ['storage', 'Файловое хранилище']]
const services = computed(() => defs.map(([id, label]) => ({ id, label, ...(data.value?.services?.[id] || {}) })))
const states = { operational: ['Работает', 'green'], error: ['Ошибка', 'red'], unavailable: ['Недоступно для проверки', 'gray'] }
const state = value => states[value] || states.unavailable
const show = value => value || 'Не указано'
const checked = value => new Intl.DateTimeFormat('ru-RU', { timeZone: 'Europe/Moscow', dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value))
async function load() { if (loading.value) return; loading.value = true; error.value = ''; try { data.value = await adminApi.systemOverview() } catch { error.value = 'Не удалось получить состояние системы.' } finally { loading.value = false } }
onMounted(load)
</script>
<template><section class="admin-overview" aria-labelledby="overview-title"><header class="admin-overview-head"><div><h3 id="overview-title">Состояние системы</h3><p v-if="data">Проверено: <time :datetime="data.checkedAt">{{ checked(data.checkedAt) }}</time></p><p v-else>Состояние приложения и основных зависимостей</p></div><button type="button" class="secondary" :disabled="loading" @click="load">{{ loading ? 'Обновление…' : 'Обновить' }}</button></header><div v-if="error" class="admin-state admin-state--error" role="alert"><span>{{ error }}<template v-if="data"> Показаны последние полученные данные.</template></span><button v-if="!data" type="button" class="secondary" @click="load">Повторить</button></div><div class="admin-overview-grid" :aria-busy="loading"><section class="admin-overview-block"><h4>Приложение</h4><dl v-if="data" class="admin-overview-facts"><div><dt>Название</dt><dd>{{ data.application.name }}</dd></div><div><dt>Версия</dt><dd>{{ show(data.application.version) }}</dd></div><div><dt>Commit</dt><dd>{{ show(data.application.commitSha) }}</dd></div><div><dt>Сборка</dt><dd>{{ show(data.application.builtAt) }}</dd></div></dl><div v-else-if="loading" class="admin-overview-skeleton" aria-label="Загрузка"><span v-for="n in 4" :key="n" /></div><p v-else class="admin-overview-placeholder">Данные не получены</p></section><section class="admin-overview-block"><h4>Сервисы</h4><div v-if="data" class="admin-service-list"><div v-for="service in services" :key="service.id" class="admin-service-row"><div><strong>{{ service.label }}</strong><small v-if="service.message">{{ service.message }}</small></div><span class="badge" :class="state(service.status)[1]">{{ state(service.status)[0] }}</span></div></div><div v-else-if="loading" class="admin-overview-skeleton" aria-label="Загрузка"><span v-for="n in 4" :key="n" /></div><p v-else class="admin-overview-placeholder">Данные не получены</p></section></div></section></template>
