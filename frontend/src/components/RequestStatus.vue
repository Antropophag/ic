<script setup>
defineProps({
  label: { type: String, required: true },
  tone: { type: String, required: true },
  fullLabel: { type: String, default: '' },
})
</script>

<template>
  <span class="shlz-status shlz-status--neutral request-status" :title="fullLabel || label">
    <span class="shlz-badge-dot request-status-dot" :class="tone" aria-hidden="true"></span>
    <span class="request-status-text">{{ label }}</span>
  </span>
</template>
