<script setup>
import AppIcon from './AppIcon.vue'

defineProps({
  iconUrl: { type: String, default: '' },
  title: { type: String, required: true },
  meta: { type: String, default: '' },
  metaTitle: { type: String, default: '' },
  openLabel: { type: String, required: true },
  downloadLabel: { type: String, required: true },
})

defineEmits(['open', 'download'])
</script>

<template>
  <div class="shlz-file-row">
    <span v-if="iconUrl" class="shlz-file-row__visual" aria-hidden="true"><img :src="iconUrl" alt="" /></span>
    <span class="shlz-file-row__content">
      <button type="button" class="shlz-file-row__primary" :title="title" :aria-label="openLabel" @click="$emit('open')">{{ title }}</button>
      <small v-if="meta" class="shlz-file-row__meta" :title="metaTitle || undefined">{{ meta }}</small>
    </span>
    <span class="shlz-file-row__actions">
      <button type="button" class="shlz-file-row__action" :aria-label="downloadLabel" @click.stop="$emit('download')"><AppIcon name="download" :size="20" :shlz="true" /></button>
    </span>
  </div>
</template>
