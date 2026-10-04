<script setup>
defineProps({ label: { type: String, required: true } })
</script>

<template>
  <button class="shlz-table__affordance shlz-table__sorter" type="button" :aria-label="label" :title="label">
    <slot />
    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path class="shlz-table__sort-down" d="M8.16294 12.7726L10.2743 9.81669C10.3689 9.68432 10.2742 9.50044 10.1116 9.50044H5.88883C5.72615 9.50044 5.63153 9.68432 5.72608 9.81669L7.83744 12.7726C7.91721 12.8843 8.08317 12.8843 8.16294 12.7726Z" />
      <path class="shlz-table__sort-up" d="M8.16294 3.22828L10.2743 6.18419C10.3689 6.31656 10.2742 6.50044 10.1116 6.50044H5.88883C5.72615 6.50044 5.63153 6.31656 5.72608 6.18419L7.83744 3.22828C7.91721 3.11661 8.08317 3.11661 8.16294 3.22828Z" />
    </svg>
  </button>
</template>
