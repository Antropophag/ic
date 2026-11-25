<script setup>
import { computed } from 'vue'
import CopyButton from './CopyButton.vue'
import { requestReference } from '../requestDocumentCopy'

const props = defineProps({ request: { type: Object, required: true }, actionsReady: { type: Boolean, default: true } })
defineEmits(['edit-department'])
const reference = computed(() => requestReference(props.request))
const missingIdentity = computed(() => [
  !props.request.initiator?.trim() && 'Инициатор не указан',
  !props.request.initiatorPosition?.trim() && 'Должность не указана',
].filter(Boolean).join(' · '))
const department = computed(() => props.request.department?.trim() || 'Подразделение не указано')
</script>

<template>
  <div class="request-heading">
    <div class="request-heading-title">
      <h1>Заявка №{{ request.id }} от {{ request.date }}</h1>
      <div class="request-heading-initiator">
        <p v-if="reference.authorLine">{{ reference.authorLine }}</p>
        <p v-if="missingIdentity" class="request-heading-missing">{{ missingIdentity }}</p>
      </div>
      <CopyButton :key="request.backendId" :text="reference.text" label="Копировать заявку с датой и инициатором" :success="reference.success" />
    </div>
    <div class="request-heading-department">
      <span class="request-heading-label">Подразделение:</span>
      <p>{{ department }}</p>
      <button v-if="request.canEditDepartment && actionsReady" type="button" class="shlz-button shlz-button--text shlz-button--xs" aria-label="Изменить подразделение заявки" @click="$emit('edit-department')">Изменить</button>
    </div>
  </div>
</template>

<style scoped>
.request-heading { min-width: 0; overflow-wrap: anywhere; }
.request-heading-title { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 8px; align-items: start; }
.request-heading-title h1 { min-width: 0; grid-column: 1; grid-row: 1; }
.request-heading-title > .copy-control { grid-column: 2; grid-row: 1 / 3; }
.request-heading-initiator { min-width: 0; grid-column: 1; grid-row: 2; font-size: 13px; line-height: 1.5; color: var(--shlz-semantic-color-text-primary); }
.request-heading-initiator p { margin: 0; }
.request-heading-missing, .request-heading-label { color: var(--muted); }
.request-heading-department { display: flex; align-items: baseline; flex-wrap: wrap; gap: 4px 8px; margin-top: 8px; font-size: 12px; line-height: 1.5; }
.request-heading-department p { min-width: 0; margin: 0; }
.request-heading-department button { flex: none; }
</style>
