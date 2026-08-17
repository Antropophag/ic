<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import AppIcon from "./AppIcon.vue";

defineProps({
  summary: { type: Object, required: true },
  showExecutors: { type: Boolean, default: false },
});
const DIRECTION_ICONS = { mechanical: "cog", metrology: "gauge", electrical: "bolt", unclassified: "file" };
const panel = ref(null);
const openDirection = ref(null);
let disclosureTrigger = null;

function toggleDirection(directionId, event) {
  disclosureTrigger = event.currentTarget;
  openDirection.value = openDirection.value === directionId ? null : directionId;
}

function closeDirection(restoreFocus = false) {
  openDirection.value = null;
  if (restoreFocus) disclosureTrigger?.focus();
}

function handleOutsidePointer(event) {
  if (openDirection.value && !panel.value?.contains(event.target)) closeDirection();
}

onMounted(() => document.addEventListener("pointerdown", handleOutsidePointer));
onBeforeUnmount(() => document.removeEventListener("pointerdown", handleOutsidePointer));
</script>

<template>
  <section id="operational-summary-panel" ref="panel" class="operational-summary" aria-labelledby="operational-summary-title" @keydown.esc.stop.prevent="closeDirection(true)">
    <div class="operational-heading">
      <div><h2 id="operational-summary-title">Монитор ИЦ</h2><p>Текущая нагрузка</p></div>
      <div class="operational-services" aria-label="Заявки в работе по службам">
        <span class="operational-service operational-service--ic">
          <span class="operational-service-icon" aria-hidden="true"><AppIcon class="operational-service-glyph" name="gauge" :size="16" /></span>
          <span class="operational-service-copy"><b>{{ summary.active }}</b><small>В работе ИЦ</small></span>
        </span>
        <span class="operational-service operational-service--expertise">
          <span class="operational-service-icon" aria-hidden="true"><AppIcon class="operational-service-glyph" name="file-check" :size="16" /></span>
          <span class="operational-service-copy"><b>{{ summary.expertise }}</b><small><span>Подготовка</span><span>заключения</span></small></span>
        </span>
        <span class="operational-service operational-service--security">
          <span class="operational-service-icon" aria-hidden="true"><AppIcon class="operational-service-glyph" name="shield-check" :size="16" /></span>
          <span class="operational-service-copy"><b>{{ summary.security_review }}</b><small>Контроль СБ</small></span>
        </span>
      </div>
    </div>
    <div class="direction-monitor" aria-label="Текущая нагрузка по направлениям испытаний">
      <div v-for="direction in summary.directions" :key="direction.id" class="direction-row" :class="[`direction-row--${direction.color}`, { 'direction-row--open': openDirection === direction.id }]">
        <button v-if="showExecutors" type="button" class="direction-trigger" :aria-expanded="openDirection === direction.id" :aria-controls="`direction-details-${direction.id}`" :aria-label="`${direction.title}: ${openDirection === direction.id ? 'скрыть' : 'показать'} детализацию нагрузки`" @click="toggleDirection(direction.id, $event)">
          <span class="direction-icon" aria-hidden="true"><AppIcon :name="DIRECTION_ICONS[direction.id]" :size="20" /></span>
          <span class="direction-name"><b>{{ direction.title }}</b></span>
          <span class="direction-volume"><b>{{ direction.active }}</b><small>в работе</small></span>
        </button>
        <template v-else><span class="direction-icon" aria-hidden="true"><AppIcon :name="DIRECTION_ICONS[direction.id]" :size="20" /></span><span class="direction-name"><b>{{ direction.title }}</b></span><span class="direction-volume"><b>{{ direction.active }}</b><small>в работе</small></span></template>
        <span v-if="showExecutors" :id="`direction-details-${direction.id}`" class="direction-popover" role="region" :aria-label="`Нагрузка: ${direction.title}`">
          <strong>Исполнители</strong>
          <span v-if="direction.executors.length || direction.unassigned" class="direction-people">
            <span v-if="direction.unassigned">
              <b>Не назначен</b><small>{{ direction.unassigned }} без исполнителя</small>
            </span>
            <span v-for="executor in direction.executors" :key="executor.user_id ?? 'unavailable'">
              <b>{{ executor.display_name }}</b>
              <small>{{ executor.in_progress ?? 0 }} в работе · {{ executor.suspended ?? 0 }} приостановлено</small>
            </span>
          </span>
          <span v-else class="direction-empty">Нет назначенных исполнителей</span>
        </span>
      </div>
    </div>
    <p class="operational-analytics-note">Скоро здесь будет больше аналитических данных.</p>
  </section>
</template>
