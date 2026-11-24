<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import copyIcon from '../vendor/shlz/copy.svg'
import closeIcon from '../vendor/shlz/close-remove.svg'
import { copyTextSelection } from '../copyTextSelection'

const copySymbolStyle = { '--copy-symbol': `url("${copyIcon}")` }

const props = defineProps({
  text: { type: String, required: true },
  label: { type: String, required: true },
  success: { type: String, required: true },
})
const control = ref(null)
const resultTarget = ref('body')
const pending = ref(false)
const result = ref('')
const failed = ref(false)
let generation = 0
let hideTimer
let hovered = false
let focused = false

function scheduleDismissal() {
  clearTimeout(hideTimer)
  if (!result.value || failed.value || hovered || focused) return
  const delay = failed.value || result.value.length > 80 ? 10000 : 3000
  hideTimer = setTimeout(() => { result.value = '' }, delay)
}
function setHovered(value) {
  hovered = value
  scheduleDismissal()
}
function setFocused(value) {
  focused = value
  scheduleDismissal()
}
watch(result, scheduleDismissal, { flush: 'sync' })

function reset() {
  clearTimeout(hideTimer)
  hovered = false
  focused = false
  generation += 1
  pending.value = false
  result.value = ''
}
watch(() => props.text, reset, { flush: 'sync' })
// Only one clipboard result is shown across independent copy targets.
onMounted(() => {
  resultTarget.value = control.value.closest('[role="dialog"], [role="alertdialog"], dialog') || document.body
  window.addEventListener('ic:copy-start', reset)
})
onBeforeUnmount(() => {
  window.removeEventListener('ic:copy-start', reset)
  reset()
})

function dismiss() {
  result.value = ''
  control.value?.querySelector('button')?.focus()
}

async function copy() {
  if (pending.value) return
  window.dispatchEvent(new Event('ic:copy-start'))
  const token = ++generation
  pending.value = true
  result.value = ''
  try {
    let copied = false
    if (typeof navigator.clipboard?.writeText === 'function') {
      try {
        await navigator.clipboard.writeText(props.text)
        copied = true
      } catch {
        // HTTP intranet pages and browser permissions may require a selection-based copy.
      }
    }
    if (token !== generation) return
    if (!copied) copied = copyTextSelection(props.text, control.value.closest('[role="dialog"], [role="alertdialog"], dialog') || document.body)
    if (!copied) throw new Error('Copy unavailable')
    if (token !== generation) return
    failed.value = false
    result.value = props.success
  } catch {
    if (token !== generation) return
    failed.value = true
    result.value = 'Не удалось скопировать. Выделите текст и скопируйте вручную.'
  } finally {
    if (token === generation) pending.value = false
  }
}
</script>

<template>
  <span ref="control" class="copy-control">
    <button type="button" class="shlz-button shlz-button--text shlz-button--sm shlz-button--icon" :aria-label="label" :title="label" :aria-busy="pending" @click="copy">
      <span class="shlz-button__icon copy-symbol" :style="copySymbolStyle" aria-hidden="true"></span>
    </button>
    <Teleport :to="resultTarget">
      <span v-if="result" class="shlz-notification shlz-notification--light copy-result" :role="failed ? 'alert' : 'status'" @mouseenter="setHovered(true)" @mouseleave="setHovered(false)" @focusin="setFocused(true)" @focusout="setFocused($event.currentTarget.contains($event.relatedTarget))">
        <span class="shlz-notification__content">{{ result }}<textarea v-if="failed" class="copy-manual" readonly :value="text" aria-label="Текст для ручного копирования" @focus="$event.target.select()" @click="$event.target.select()"></textarea></span>
        <button type="button" class="shlz-notification__close" aria-label="Закрыть уведомление о копировании" @click="dismiss"><img :src="closeIcon" alt="" /></button>
      </span>
    </Teleport>
  </span>
</template>

<style scoped>
.copy-manual { display: block; width: 100%; min-height: 72px; margin-top: 8px; padding: 8px; resize: vertical; font: inherit; color: inherit; background: #fff; border: 1px solid var(--line); border-radius: 6px; }
.copy-control { display: inline-flex; align-items: center; flex: none; }
.copy-symbol { background: currentColor; mask: var(--copy-symbol) center / contain no-repeat; }
.copy-result { position: fixed; z-index: 100; inset-inline: 16px; bottom: 24px; margin-inline: auto; max-inline-size: calc(100% - 32px); }
</style>
