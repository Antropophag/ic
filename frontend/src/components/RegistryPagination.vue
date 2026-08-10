<script setup>
import AppIcon from './AppIcon.vue'

defineProps({
  page: { type: Number, required: true },
  pageCount: { type: Number, required: true },
  pageNumbers: { type: Array, required: true },
  pageSize: { type: Number, required: true },
  pageSizes: { type: Array, required: true },
  total: { type: Number, required: true },
})

defineEmits(['page', 'page-size'])
</script>

<template>
  <nav class="shlz-pagination registry-pagination" aria-label="Страницы реестра заявок">
    <ul v-if="pageCount > 1" class="shlz-pagination__list">
      <li>
        <span v-if="page <= 1" class="shlz-pagination__item shlz-pagination__item--disabled" aria-disabled="true"><AppIcon class="shlz-pagination__icon" name="chevron-left" :size="20" :shlz="true" /></span>
        <a v-else class="shlz-pagination__item" href="#" aria-label="Предыдущая страница" @click.prevent="$emit('page', page - 1)"><AppIcon class="shlz-pagination__icon" name="chevron-left" :size="20" :shlz="true" /></a>
      </li>
      <li v-for="number in pageNumbers" :key="number">
        <a class="shlz-pagination__item" href="#" :aria-label="`Страница ${number}`" :aria-current="number === page ? 'page' : undefined" @click.prevent="$emit('page', number)">{{ number }}</a>
      </li>
      <li>
        <span v-if="page >= pageCount" class="shlz-pagination__item shlz-pagination__item--disabled" aria-disabled="true"><AppIcon class="shlz-pagination__icon" name="chevron-right" :size="20" :shlz="true" /></span>
        <a v-else class="shlz-pagination__item" href="#" aria-label="Следующая страница" @click.prevent="$emit('page', page + 1)"><AppIcon class="shlz-pagination__icon" name="chevron-right" :size="20" :shlz="true" /></a>
      </li>
    </ul>
    <div class="shlz-pagination__group registry-pagination__summary">
      <span class="shlz-pagination__summary">{{ (page - 1) * pageSize + 1 }}–{{ Math.min(page * pageSize, total) }} из {{ total }}</span>
      <span class="shlz-pagination__page-size-label">На странице</span>
      <button v-for="size in pageSizes" :key="size" type="button" class="shlz-pagination__item" :class="{ 'shlz-pagination__item--visual-pressed': pageSize === size }" :aria-pressed="pageSize === size" @click="$emit('page-size', size)">{{ size }}</button>
    </div>
  </nav>
</template>
