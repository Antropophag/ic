<script setup>
import { computed, ref, useId, watch } from 'vue'
import AppIcon from './AppIcon.vue'
import RequestObjectsTable from './RequestObjectsTable.vue'

const props = defineProps({
  objects: { type: Array, required: true },
  roles: { type: Array, default: () => [] },
  manufacturer: { type: String, default: '' },
  supplier: { type: String, default: '' },
  scope: { type: String, default: '' },
})
const listId = useId()
const multiple = computed(() => props.objects.length > 1)
const expandByDefault = computed(() => props.roles.some(role => ['ic_manager', 'laboratory_manager', 'ic_executor'].includes(role)))
const expanded = ref(false)
watch([expandByDefault, () => props.objects.length], () => { expanded.value = expandByDefault.value }, { immediate: true })
</script>

<template>
  <article class="card object-band request-entity-head request-object-summary">
    <slot name="corner"></slot>
    <div class="request-objects-header">
      <div class="request-objects-heading">
        <h2>{{ multiple ? 'Объекты испытаний' : 'Объект испытаний' }}<span v-if="multiple" class="request-objects-count"> · {{ objects.length }}</span></h2>
        <button v-if="multiple" type="button" class="shlz-button shlz-button--text shlz-button--sm" :aria-expanded="expanded" :aria-controls="listId" @click="expanded = !expanded">
          {{ expanded ? 'Свернуть список' : 'Показать объекты' }}
          <AppIcon class="request-objects-chevron" :class="{ 'is-expanded': expanded }" name="chevron-right" :size="16" />
        </button>
      </div>
    </div>
    <div :id="listId" :hidden="multiple && !expanded" class="request-objects-list">
      <RequestObjectsTable :objects="objects" :supplier="supplier" :compact-single="true" />
    </div>
    <section class="request-overview" aria-label="Общие реквизиты заявки">
      <div class="facts-row">
        <div class="fact"><span>Производитель</span><b>{{ manufacturer || '—' }}</b></div>
        <div class="fact"><span>Поставщик</span><b>{{ supplier || '—' }}</b></div>
      </div>
      <div class="method-row"><span>Объём испытаний</span><p>{{ scope || '—' }}</p></div>
    </section>
  </article>
</template>

<style scoped>
.request-objects-header { display: flex; align-items: center; justify-content: space-between; gap: 12px 24px; flex-wrap: wrap; margin-bottom: 8px; }
.request-objects-heading { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 16px; min-width: 0; }
.request-objects-heading h2 { margin: 0; font-size: 13px; line-height: 20px; }
.request-objects-count { color: var(--muted); font-weight: 400; }
.request-objects-chevron { transform: rotate(90deg); }
.request-objects-chevron.is-expanded { transform: rotate(-90deg); }
.request-object-summary .request-overview { margin-top: 16px; border-top: 0; }
.request-object-summary .facts-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.request-object-summary .fact b, .request-object-summary .method-row p { overflow-wrap: anywhere; }
@media (max-width: 600px) {
  .request-objects-header { gap: 12px; }
}
</style>
