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
</script>

<template>
  <section class="page admin-page">
    <div class="card">
      <div class="section-title admin-heading"><h3>Администрирование</h3><button type="button" class="secondary" @click="emit('close')">← К реестру заявок</button></div>
      <div class="admin-tabs" role="tablist" aria-label="Разделы администрирования">
        <button v-for="tab in tabs" :id="`admin-tab-${tab[0]}`" :key="tab[0]" type="button" role="tab" :aria-selected="active === tab[0]" :tabindex="active === tab[0] ? 0 : -1" :class="{ active: active === tab[0] }" @click="active = tab[0]">{{ tab[1] }}</button>
      </div>
      <AdminUsers v-if="active === 'users'" />
      <AdminAuditLog v-else-if="active === 'audit'" @open-request="emit('open-request', $event)" />
      <AdminNotifications v-else @open-request="emit('open-request', $event)" />
    </div>
  </section>
</template>
