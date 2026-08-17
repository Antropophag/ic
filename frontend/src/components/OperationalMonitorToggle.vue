<script setup>
import AppIcon from "./AppIcon.vue";

defineProps({ open: { type: Boolean, required: true } });
defineEmits(["toggle"]);
</script>

<template>
  <button
    type="button"
    class="monitor-toggle"
    :aria-expanded="open"
    aria-controls="operational-summary-panel"
    @click="$emit('toggle')"
  >
    <AppIcon name="gauge" :size="17" />
    Монитор ИЦ
    <AppIcon name="chevron-down" :size="14" class="monitor-toggle-chevron" />
  </button>
</template>
