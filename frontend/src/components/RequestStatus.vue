<script setup>
defineProps({
  label: { type: String, required: true },
  fullLabel: { type: String, default: '' },
})
</script>

<template>
  <span class="shlz-status shlz-status--neutral request-status" :title="fullLabel || label">
    <span class="request-status-text">{{ label }}</span>
  </span>
</template>
