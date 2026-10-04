<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { REQUEST_COLORS, testingDirectionLabel } from '../registry'

const props = defineProps({ modelValue: { type: Array, default: () => [] }, active: { type: Boolean, default: true } })
const emit = defineEmits(['update:modelValue'])
const id = `direction-filter-${useId()}`
const trigger = ref(null)
const panel = ref(null)
const expanded = ref(false)
const draft = ref([])
const state = computed(() => props.modelValue.length
  ? `Выбраны направления: ${props.modelValue.map(testingDirectionLabel).join(', ')}`
  : 'Все направления испытаний')

function positionPanel() {
  if (!expanded.value || !panel.value || !trigger.value) return
  const anchor = trigger.value.getBoundingClientRect()
  let box = panel.value.getBoundingClientRect()
  // The portal uses root zoom; fixed offsets need local CSS units.
  const scale = box.width / panel.value.offsetWidth || 1
  panel.value.style.maxInlineSize = `${(window.innerWidth - 16) / scale}px`
  panel.value.style.maxBlockSize = `${(window.innerHeight - 16) / scale}px`
  box = panel.value.getBoundingClientRect()
  const left = Math.max(8, Math.min(anchor.left, window.innerWidth - box.width - 8))
  const below = anchor.bottom + 8
  const top = below + box.height <= window.innerHeight - 8
    ? below : Math.max(8, anchor.top - box.height - 8)
  Object.assign(panel.value.style, { left: `${left / scale}px`, top: `${top / scale}px` })
}

function beforeToggle(event) {
  if (event.newState !== 'open') return
  draft.value = [...props.modelValue]
  expanded.value = true
  panel.value.style.visibility = 'hidden'
}

async function toggled(event) {
  expanded.value = event.newState === 'open'
  if (!expanded.value) return
  await nextTick()
  if (!expanded.value || !panel.value) return
  positionPanel()
  panel.value.style.visibility = 'visible'
  panel.value?.querySelector('input')?.focus()
}

function close() {
  if (!expanded.value) return
  expanded.value = false
  panel.value?.hidePopover()
}

function apply(values) {
  emit('update:modelValue', [...values])
  cancel()
}

function cancel() {
  close()
  trigger.value?.focus()
}

function handleEscape(event) {
  // Safari does not focus checkboxes clicked with a mouse; Escape may target body.
  if (!expanded.value || event.key !== 'Escape') return
  event.preventDefault()
  event.stopPropagation()
  cancel()
}

watch(() => props.active, active => { if (!active) close() })
onMounted(() => {
  window.addEventListener('resize', positionPanel)
  window.addEventListener('scroll', positionPanel, true)
  document.addEventListener('keydown', handleEscape, true)
})
onBeforeUnmount(() => {
  expanded.value = false
  window.removeEventListener('resize', positionPanel)
  window.removeEventListener('scroll', positionPanel, true)
  document.removeEventListener('keydown', handleEscape, true)
})
</script>

<template>
  <button
    ref="trigger" class="shlz-table__affordance shlz-table__filter" type="button"
    aria-label="Фильтр по направлению испытаний" :title="state" aria-haspopup="dialog"
    :aria-expanded="expanded" :aria-controls="id" :aria-describedby="`${id}-state`"
    :data-filter-active="modelValue.length > 0" :popovertarget="id"
  >
    <svg viewBox="0 0 16 18" aria-hidden="true" focusable="false"><path d="M9.79004 10.4521V12.6426C9.78998 12.8402 9.63109 12.9999 9.43457 13H6.50293C6.30632 13 6.14752 12.8403 6.14746 12.6426V10.4521H9.79004ZM12.0811 5C12.3548 5 12.5257 5.29816 12.3906 5.53613L9.91797 9.7373H6.02051L3.54883 5.53613C3.41142 5.29818 3.58181 5.0001 3.85547 5H12.0811Z" /></svg>
  </button>
  <span :id="`${id}-state`" class="visually-hidden">{{ state }}</span>
  <Teleport to="body">
    <dialog
      :id="id" ref="panel" popover="auto" :aria-labelledby="`${id}-title`"
      class="shlz-popover direction-filter-panel" @beforetoggle="beforeToggle" @toggle="toggled"
    >
      <div :id="`${id}-title`" class="shlz-popover__header">Направления испытаний</div>
      <div class="shlz-popover__body shlz-popover__body--fluid">
        <label v-for="color in REQUEST_COLORS" :key="color" class="shlz-choice direction-filter-option">
          <input v-model="draft" class="shlz-checkbox shlz-checkbox--sm" type="checkbox" :value="color" />
          <span>{{ testingDirectionLabel(color) }}</span><span class="request-color-dot" :class="color" aria-hidden="true"></span>
        </label>
        <p class="direction-filter-hint">Без выбранных пунктов — все направления.</p>
        <div class="direction-filter-actions">
          <button class="shlz-button shlz-button--primary" type="button" @click="apply(draft)">Применить</button>
          <button class="shlz-button" type="button" @click="apply([])">Сбросить</button>
        </div>
      </div>
    </dialog>
  </Teleport>
</template>
