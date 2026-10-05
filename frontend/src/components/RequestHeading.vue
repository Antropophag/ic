<script setup>
import { computed } from 'vue'
import CopyButton from './CopyButton.vue'
import { requestReference } from '../requestDocumentCopy'

const props = defineProps({ request: { type: Object, required: true }, actionsReady: { type: Boolean, default: true } })
defineEmits(['edit-department'])
const reference = computed(() => requestReference(props.request))
const initiator = computed(() => props.request.initiator?.trim() || 'Инициатор не указан')
const position = computed(() => props.request.initiatorPosition?.trim() || 'Должность не указана')
const department = computed(() => props.request.department?.trim() || 'Подразделение не указано')
</script>

<template>
  <div class="request-heading">
    <div class="request-heading-title">
      <h1>Заявка №{{ request.id }} от {{ request.date }}</h1>
      <CopyButton :key="request.backendId" :text="reference.text" label="Копировать заявку с датой и инициатором" :success="reference.success" />
    </div>
    <div class="request-heading-initiator">
      <p><span class="request-heading-label">Инициатор:</span> {{ initiator }}</p>
      <span class="request-heading-separator" aria-hidden="true"> · </span>
      <span>{{ position }}</span><span aria-hidden="true"> · </span>
      <div class="request-heading-department">
        <p>{{ department }}</p>
        <button v-if="request.canEditDepartment && actionsReady" type="button" class="shlz-button shlz-button--text shlz-button--xs" aria-label="Изменить подразделение заявки" @click="$emit('edit-department')">Изменить</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.request-heading { min-width: 0; overflow-wrap: anywhere; }
.request-heading-title { display: flex; align-items: center; gap: 8px; }
.request-heading-title h1 { min-width: 0; }
.request-heading-initiator { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0 6px; margin-top: 6px; font-size: 13px; line-height: 1.5; color: var(--muted); }
.request-heading-initiator p { margin: 0; }
.request-heading-initiator > p:first-child { color: var(--shlz-semantic-color-text-primary); }
.request-heading-label { color: var(--muted); }
.request-heading-department { display: inline-flex; align-items: baseline; gap: 8px; }
.request-heading-department p { min-width: 0; }
.request-heading-department button { flex: none; }
</style>
