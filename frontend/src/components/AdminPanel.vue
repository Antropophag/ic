<script setup>
import { ref } from 'vue'
import AdminUsers from './AdminUsers.vue'
import AdminAuditLog from './AdminAuditLog.vue'
import AdminNotifications from './AdminNotifications.vue'

const emit = defineEmits(['close', 'open-request'])
const active = ref('users')
const tabs = [
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
    <div class="card">
      <div class="section-title admin-heading"><h3>Администрирование</h3><button type="button" class="secondary" @click="emit('close')">← К реестру заявок</button></div>
      <div class="admin-tabs" role="tablist" aria-label="Разделы администрирования">
        <button v-for="tab in tabs" :id="`admin-tab-${tab[0]}`" :key="tab[0]" type="button" role="tab" :aria-selected="active === tab[0]" :aria-controls="`admin-panel-${tab[0]}`" :tabindex="active === tab[0] ? 0 : -1" :class="{ active: active === tab[0] }" @click="active = tab[0]" @keydown.right.prevent="moveTab(1)" @keydown.left.prevent="moveTab(-1)">{{ tab[1] }}</button>
      </div>
      <AdminUsers v-if="active === 'users'" id="admin-panel-users" role="tabpanel" aria-labelledby="admin-tab-users" />
      <AdminAuditLog v-else-if="active === 'audit'" id="admin-panel-audit" role="tabpanel" aria-labelledby="admin-tab-audit" @open-request="emit('open-request', $event)" />
      <AdminNotifications v-else id="admin-panel-notifications" role="tabpanel" aria-labelledby="admin-tab-notifications" @open-request="emit('open-request', $event)" />
    </div>
  </section>
</template>
