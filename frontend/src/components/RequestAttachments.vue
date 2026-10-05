<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { FileUploadController } from '../vendor/shlz/file-upload'
import AppIcon from './AppIcon.vue'
import cloud from '../vendor/shlz/cloud-upload.svg'
import pdf from '../vendor/shlz/file-pdf-default.svg'
import docx from '../vendor/shlz/file-docx.svg'
import xlsx from '../vendor/shlz/file-xlsx.svg'
import png from '../vendor/shlz/file-png.svg'
import img from '../vendor/shlz/file-img.svg'
import generic from '../vendor/shlz/file-generic.svg'

const props = defineProps({ modelValue: { type: Array, default: () => [] }, disabled: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])
const root = ref(null)
const input = ref(null)
const id = useId()
const notice = ref('')
const cloudStyle = { '--upload-symbol': `url("${cloud}")` }
const icons = { pdf, docx, xlsx, png, jpg: img, jpeg: img }
let controller

function extension(file) { return file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '' }
function icon(file) { return Object.hasOwn(icons, extension(file)) ? icons[extension(file)] : generic }
function size(file) {
  if (file.size < 1024) return `${file.size} Б`
  const megabytes = file.size >= 1024 * 1024
  return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(file.size / (megabytes ? 1024 * 1024 : 1024))} ${megabytes ? 'МБ' : 'КБ'}`
}
function selectFiles(event) {
  if (props.disabled) return
  const added = Array.from(event.detail.files)
  emit('update:modelValue', [...props.modelValue, ...added])
  notice.value = `Добавлено файлов: ${added.length}.`
  input.value.value = ''
}
async function remove(index) {
  if (props.disabled || !props.modelValue[index]) return
  notice.value = `Файл «${props.modelValue[index].name}» удалён из списка.`
  emit('update:modelValue', props.modelValue.filter((_, itemIndex) => itemIndex !== index))
  await nextTick()
  const buttons = root.value?.querySelectorAll('[data-remove-file]')
  ;(buttons?.[index] || buttons?.[buttons.length - 1] || input.value)?.focus()
}
onMounted(() => {
  root.value.addEventListener('shlz:file-upload-files', selectFiles)
  controller = new FileUploadController(root.value)
})
onBeforeUnmount(() => {
  root.value?.removeEventListener('shlz:file-upload-files', selectFiles)
  controller?.destroy()
})
</script>

<template>
  <fieldset class="request-attachments">
    <legend>Сопроводительные документы</legend>
    <div ref="root" class="shlz-file-upload" data-shlz-file-upload>
      <input :id="id" ref="input" class="shlz-file-upload__input" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" :disabled="disabled" aria-label="Выбрать сопроводительные документы" :aria-describedby="`${id}-hint`" />
      <label class="shlz-file-upload__surface" :for="id" :aria-disabled="disabled">
        <span class="shlz-file-upload__icon upload-symbol" :style="cloudStyle" aria-hidden="true"></span>
        <span class="shlz-file-upload__instructions">Нажмите или перетащите файлы в эту область</span>
      </label>
      <p :id="`${id}-hint`" class="attachments-hint">PDF, PNG, JPG, DOCX, XLSX · до 200 МБ на файл. Файлы загрузятся после создания заявки.</p>
      <ul v-if="modelValue.length" class="shlz-file-upload__files" aria-label="Выбранные документы">
        <li v-for="(file, index) in modelValue" :key="index" class="shlz-file-row">
          <span class="shlz-file-row__visual" aria-hidden="true"><img :src="icon(file)" alt="" /></span>
          <span class="shlz-file-row__content">
            <span class="shlz-file-row__title" :title="file.name">{{ file.name }}</span>
            <span class="shlz-file-row__meta">{{ extension(file).toUpperCase() || 'Файл' }} · {{ size(file) }}</span>
          </span>
          <span class="shlz-file-row__actions"><button type="button" class="shlz-file-row__action" data-remove-file :disabled="disabled" :aria-label="`Удалить ${file.name}`" :title="`Удалить ${file.name}`" @click="remove(index)"><AppIcon name="close" :size="16" /></button></span>
        </li>
      </ul>
      <p v-if="notice" class="visually-hidden" role="status">{{ notice }}</p>
    </div>
  </fieldset>
</template>

<style scoped>
.request-attachments { border: 0; margin: 0; padding: 0; min-width: 0; }
.request-attachments legend { margin-bottom: 8px; color: var(--muted); font-size: 13px; }
.request-attachments .shlz-file-upload { width: 100%; }
.request-attachments .shlz-file-upload__surface { display: flex; }
.request-attachments .shlz-file-upload__input { padding: 0; border: 0; }
.upload-symbol { background: currentColor; mask: var(--upload-symbol) center / contain no-repeat; }
.attachments-hint { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.5; }
</style>
