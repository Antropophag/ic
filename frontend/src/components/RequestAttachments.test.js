// @vitest-environment happy-dom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it } from 'vitest'
import RequestAttachments from './RequestAttachments.vue'

let app
afterEach(() => { app?.unmount(); document.body.replaceChildren() })
function mount(disabled = ref(false)) {
  const files = ref([])
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(RequestAttachments, { modelValue: files.value, disabled: disabled.value, 'onUpdate:modelValue': value => { files.value = value } }) })
  app.mount(root)
  return { root, files }
}
async function select(root, files) {
  const input = root.querySelector('input')
  Object.defineProperty(input, 'files', { configurable: true, value: files })
  input.dispatchEvent(new Event('change', { bubbles: true }))
  await nextTick()
}

it('adds selections, shows format/size and removes only the chosen file with keyboard focus preserved', async () => {
  const { root, files } = mount()
  await select(root, [new File(['pdf'], 'Чертёж.pdf', { type: 'application/pdf' })])
  await select(root, [new File(['table'], 'Расчёт.xlsx')])
  expect(files.value.map(file => file.name)).toEqual(['Чертёж.pdf', 'Расчёт.xlsx'])
  expect(root.textContent).toContain('PDF · 3 Б')
  expect(root.querySelectorAll('.shlz-file-row img')).toHaveLength(2)
  root.querySelector('[aria-label="Удалить Чертёж.pdf"]').click()
  await nextTick()
  expect(files.value.map(file => file.name)).toEqual(['Расчёт.xlsx'])
  expect(document.activeElement.getAttribute('aria-label')).toBe('Удалить Расчёт.xlsx')
  root.querySelector('[aria-label="Удалить Расчёт.xlsx"]').click()
  await nextTick()
  expect(files.value).toEqual([])
  expect(document.activeElement).toBe(root.querySelector('input'))
})

it('freezes selection and removal while the request is being created', async () => {
  const disabled = ref(false)
  const { root, files } = mount(disabled)
  await select(root, [new File(['pdf'], 'Чертёж.pdf')])
  disabled.value = true
  await nextTick()
  root.querySelector('button').click()
  await select(root, [new File(['other'], 'Другой.pdf')])
  expect(files.value.map(file => file.name)).toEqual(['Чертёж.pdf'])
  expect(root.querySelector('input').disabled).toBe(true)
})
