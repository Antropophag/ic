<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({ src: { type: String, required: true } })
const title = ref('')
const meta = ref('')
const sections = ref([])
const loading = ref(false)
const error = ref('')
let controller = null

async function load() {
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''
  title.value = ''
  meta.value = ''
  sections.value = []
  try {
    const response = await fetch(props.src, { signal: controller.signal })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const documentNode = new DOMParser().parseFromString(await response.text(), 'text/html')
    const main = documentNode.querySelector('main')
    if (!main) throw new Error('Help content is missing')
    title.value = main.querySelector(':scope > h1')?.textContent?.trim() || 'Инструкция'
    meta.value = main.querySelector(':scope > p')?.textContent?.trim() || ''
    const result = []
    let section = null
    for (const element of main.children) {
      if (element.tagName === 'H1' || (element.tagName === 'P' && element === main.querySelector(':scope > p'))) continue
      if (element.tagName === 'H2') {
        section = { heading: element.textContent.trim(), blocks: [] }
        result.push(section)
        continue
      }
      if (!section) continue
      if (element.tagName === 'P') section.blocks.push({ type: 'p', text: element.textContent.trim() })
      if (element.tagName === 'OL' || element.tagName === 'UL') {
        section.blocks.push({
          type: element.tagName.toLowerCase(),
          items: [...element.children].filter(item => item.tagName === 'LI').map(item => item.textContent.trim()),
        })
      }
    }
    sections.value = result
  } catch (loadError) {
    if (loadError.name !== 'AbortError') error.value = 'Не удалось загрузить инструкцию.'
  } finally {
    loading.value = false
  }
}

watch(() => props.src, load, { immediate: true })
onBeforeUnmount(() => controller?.abort())
</script>

<template>
  <div class="request-help-content">
    <p v-if="loading" class="request-help-state">Загрузка инструкции…</p>
    <div v-else-if="error" class="request-help-state request-help-state--error"><p>{{ error }}</p><button type="button" class="secondary" @click="load">Повторить</button></div>
    <article v-else>
      <h1>{{ title }}</h1>
      <p class="request-help-meta">{{ meta }}</p>
      <section v-for="section in sections" :key="section.heading">
        <h2>{{ section.heading }}</h2>
        <template v-for="(block, index) in section.blocks" :key="`${section.heading}-${index}`">
          <p v-if="block.type === 'p'">{{ block.text }}</p>
          <ol v-else-if="block.type === 'ol'"><li v-for="item in block.items" :key="item">{{ item }}</li></ol>
          <ul v-else><li v-for="item in block.items" :key="item">{{ item }}</li></ul>
        </template>
      </section>
    </article>
  </div>
</template>
