<script setup>
defineProps({ name: { type: String, required: true }, size: { type: Number, default: 18 } })
</script>

<template>
  <svg class="app-icon" :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <template v-if="name === 'close'"><path d="m7 7 10 10M17 7 7 17" /></template>
    <template v-else-if="name === 'plus'"><path d="M12 5v14M5 12h14" /></template>
    <template v-else-if="name === 'arrow-left'"><path d="m10 6-6 6 6 6M4 12h16" /></template>
    <template v-else-if="name === 'chevron-left'"><path d="m15 6-6 6 6 6" /></template>
    <template v-else-if="name === 'chevron-right'"><path d="m9 6 6 6-6 6" /></template>
    <template v-else-if="name === 'help'"><circle cx="12" cy="12" r="10" stroke-width="2" /><path d="M9.1 9a3 3 0 1 1 5.8 1c0 2-2.9 2.4-2.9 4" stroke-width="2" /><path d="M12 18h.01" stroke-width="2.5" /></template>
    <template v-else-if="name === 'send'"><path d="m4 4 16 8-16 8 3.5-8L4 4Z" /><path d="M7.5 12H20" /></template>
    <template v-else-if="name === 'download'"><path d="M12 4v12m-4-4 4 4 4-4M5 20h14" /></template>
    <template v-else-if="name === 'search'"><circle cx="10.5" cy="10.5" r="6" /><path d="m15 15 4 4" /></template>
    <template v-else-if="name === 'file'"><path d="M6 3.5h8l4 4V20H6zM14 3.5V8h4" /><path d="M9 12h6M9 15h5" /></template>
    <template v-else-if="name === 'history'"><path d="M4.5 8.5V4.5h4" /><path d="M5 5a8 8 0 1 1-1 9" /><path d="M12 8v4l2.7 1.6" /></template>
    <template v-else-if="name === 'user'"><circle cx="12" cy="8" r="3.5" /><path d="M5.5 20c.5-4.2 2.7-6.3 6.5-6.3s6 2.1 6.5 6.3" /></template>
    <template v-else-if="name === 'play'"><path d="m9 7 8 5-8 5z" /></template>
    <template v-else-if="name === 'pause'"><path d="M9 7v10M15 7v10" /></template>
    <template v-else-if="name === 'upload'"><path d="M12 16V4m-4 4 4-4 4 4M5 20h14" /></template>
    <template v-else-if="name === 'trash'"><path d="M5 7h14M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" /></template>
    <template v-else-if="name === 'file-check'"><path d="M6 3.5h8l4 4V20H6zM14 3.5V8h4" /><path d="m9 14 2 2 4-4" /></template>
    <template v-else-if="name === 'shield-check'"><path d="M12 3 19 6v5c0 4.4-2.7 7.5-7 10-4.3-2.5-7-5.6-7-10V6z" /><path d="m9 12 2 2 4-4" /></template>
    <template v-else-if="name === 'return'"><path d="m9 7-5 5 5 5" /><path d="M4 12h9c4 0 6 2 6 6" /></template>
    <template v-else-if="name === 'building'"><path d="M5 21V5l7-2v18M12 8h7v13M3 21h18M8 8h1M8 12h1M8 16h1M15 11h1M15 15h1" /></template>
  </svg>
</template>
