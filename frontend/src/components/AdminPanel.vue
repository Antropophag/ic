<script setup>
import { ref } from 'vue'
import AdminUsers from './AdminUsers.vue'
import AdminAuditLog from './AdminAuditLog.vue'
import AdminNotifications from './AdminNotifications.vue'
import AdminOverview from './AdminOverview.vue'
import AppIcon from './AppIcon.vue'

const emit = defineEmits(['close', 'open-request'])
const active = ref('overview')
const tabs = [
  ['overview', 'Обзор'],
  ['users', 'Пользователи и роли'],
  ['audit', 'Журнал действий'],
  ['notifications', 'Уведомления'],
]
function moveTab(step) {
  const index = tabs.findIndex(([id]) => id === active.value)
  const [next] = tabs[(index + step + tabs.length) % tabs.length]
  active.value = next
  requestAnimationFrame(() => document.getElementById(`admin-tab-${next}`)?.focus())
}
</script>

<template>
  <section class="page admin-page">
    <div class="card admin-workspace">
      <header class="admin-heading"><div><p>Управление порталом</p><h2>Администрирование</h2></div><button type="button" class="secondary" @click="emit('close')"><AppIcon name="arrow-left" :size="16" />К реестру</button></header>
      <div class="admin-tabs" role="tablist" aria-label="Разделы администрирования">
        <button v-for="tab in tabs" :id="`admin-tab-${tab[0]}`" :key="tab[0]" type="button" role="tab" :aria-selected="active === tab[0]" :aria-controls="`admin-panel-${tab[0]}`" :tabindex="active === tab[0] ? 0 : -1" :class="{ active: active === tab[0] }" @click="active = tab[0]" @keydown.right.prevent="moveTab(1)" @keydown.left.prevent="moveTab(-1)">{{ tab[1] }}</button>
      </div>
      <div class="admin-panel-body"><AdminOverview v-if="active === 'overview'" id="admin-panel-overview" role="tabpanel" aria-labelledby="admin-tab-overview" />
        <AdminUsers v-else-if="active === 'users'" id="admin-panel-users" role="tabpanel" aria-labelledby="admin-tab-users" />
        <AdminAuditLog v-else-if="active === 'audit'" id="admin-panel-audit" role="tabpanel" aria-labelledby="admin-tab-audit" @open-request="emit('open-request', $event)" />
        <AdminNotifications v-else id="admin-panel-notifications" role="tabpanel" aria-labelledby="admin-tab-notifications" @open-request="emit('open-request', $event)" />
      </div>
    </div>
  </section>
</template>
