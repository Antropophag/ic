<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, default: '' },
  titleId: { type: String, required: true },
  descriptionId: { type: String, default: undefined },
  size: { type: String, default: 'medium' },
  busy: { type: Boolean, default: false },
  as: { type: String, default: 'section' },
  alert: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'submit'])
const dialog = ref(null)
let returnFocus = null

const focusableSelector = [
  'a[href]', 'button:not([disabled])', 'input:not([disabled])',
  'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
].join(',')

function requestClose() {
  if (!props.busy) emit('close')
}

function handleKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    requestClose()
    return
  }
  if (event.key !== 'Tab') return
  const controls = [...dialog.value.querySelectorAll(focusableSelector)]
  if (!controls.length) {
    event.preventDefault()
    dialog.value.focus()
    return
  }
  const first = controls[0]
  const last = controls.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault(); last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault(); first.focus()
  }
}

watch(() => props.open, async open => {
  if (open) {
    returnFocus = document.activeElement
    await nextTick()
    const first = dialog.value?.querySelector(focusableSelector)
    ;(first || dialog.value)?.focus()
  } else if (returnFocus?.isConnected) {
    await nextTick(); returnFocus.focus(); returnFocus = null
  }
}, { immediate: true })

onBeforeUnmount(() => {
  if (returnFocus?.isConnected) returnFocus.focus()
})
</script>

<template>
  <div v-if="open" class="overlay modal-overlay" @click.self="requestClose">
    <component
      :is="as"
      ref="dialog"
      class="modal"
      :class="`modal--${size}`"
      :role="alert ? 'alertdialog' : 'dialog'"
      aria-modal="true"
      :aria-labelledby="titleId"
      :aria-describedby="descriptionId"
      tabindex="-1"
      @keydown="handleKeydown"
      @submit.prevent="emit('submit', $event)"
    >
      <header v-if="title" class="modal-head">
        <div class="modal-heading"><slot name="eyebrow" /><h2 :id="titleId">{{ title }}</h2></div>
        <button type="button" aria-label="Закрыть" :disabled="busy" @click="requestClose"><AppIcon name="close" /></button>
      </header>
      <div class="modal-body"><slot /></div>
      <footer v-if="$slots.footer" class="modal-actions"><slot name="footer" /></footer>
    </component>
  </div>
</template>
