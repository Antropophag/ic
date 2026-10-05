<script setup>
import { computed } from 'vue'
import CopyButton from './CopyButton.vue'
import { allObjectNamesText } from '../requestDocumentCopy'
const props = defineProps({ objects: { type: Array, required: true }, supplier: { type: String, default: '' }, compactSingle: { type: Boolean, default: false } })
const multiple = computed(() => props.objects.length > 1)
</script>

<template>
  <table class="shlz-table request-objects-table" :class="{ 'request-objects-table--single': compactSingle && !multiple }">
    <caption class="visually-hidden">Объекты испытаний и количество образцов</caption>
    <colgroup><col /><col class="request-objects-quantity" /></colgroup>
    <thead class="shlz-table__head" :class="{ 'visually-hidden': compactSingle && !multiple }">
      <tr>
        <th class="shlz-table__cell" scope="col"><div class="object-title-row">
          <span>{{ multiple ? 'Наименования образцов' : 'Наименование объекта' }}</span>
          <CopyButton v-if="multiple" :text="allObjectNamesText(objects, supplier)" label="Копировать все образцы и поставщика" success="Все образцы и поставщик скопированы" />
        </div></th>
        <th class="shlz-table__cell request-objects-quantity" scope="col">Количество образцов</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(object, index) in objects" :key="index" class="shlz-table__row">
        <th class="shlz-table__cell" scope="row">
          <div class="object-title-row">
            <span v-if="multiple" class="request-object-number" aria-hidden="true">{{ index + 1 }}.</span>
            <span class="request-object-name">{{ object.name || 'Наименование не указано' }}</span>
            <CopyButton v-if="object.name" :text="object.name" :label="multiple ? `Копировать наименование объекта ${index + 1}` : 'Копировать наименование объекта'" success="Наименование объекта скопировано" />
          </div>
        </th>
        <td class="shlz-table__cell request-objects-quantity"><span v-if="compactSingle && !multiple" class="request-quantity-label">Количество образцов</span>{{ object.sampleQuantityText || 'Не указано' }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.request-objects-table { width: 100%; table-layout: fixed; }
.request-objects-table .shlz-table__cell { padding: 8px; vertical-align: middle; overflow-wrap: anywhere; }
.request-objects-table tbody th { font-size: 15px; font-weight: 500; text-transform: none; color: var(--shlz-semantic-color-text-primary); background: transparent; }
.request-objects-table .request-objects-quantity { width: 140px; }
.request-objects-table--single tbody .shlz-table__cell { border-top: 0; padding-top: 0; padding-bottom: 4px; }
.request-objects-table--single tbody th { font-size: 17px; line-height: 1.45; }
.request-quantity-label { display: block; margin-bottom: 3px; color: var(--muted); font-size: 10px; line-height: 14px; font-weight: 400; }
.request-objects-table tbody tr { cursor: default; }
.object-title-row { display: flex; align-items: center; gap: 8px; min-width: 0; }
.request-object-name { min-width: 0; overflow-wrap: anywhere; }
.request-object-number { flex: none; color: var(--muted); font-weight: 400; }
@media (max-width: 600px) {
  .request-objects-table .shlz-table__cell { padding: 8px 4px; }
  .request-objects-table .request-objects-quantity { width: 100px; }
}
</style>
