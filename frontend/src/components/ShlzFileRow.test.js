// @vitest-environment happy-dom

import { createApp, nextTick } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import ShlzFileRow from './ShlzFileRow.vue'

let mounted

afterEach(() => {
  mounted?.app.unmount()
  mounted?.root.remove()
  mounted = undefined
})

it('keeps open and download actions on the current public File Row contract', async () => {
  const root = document.createElement('div')
  document.body.append(root)
  const onOpen = vi.fn()
  const onDownload = vi.fn()
  const app = createApp(ShlzFileRow, {
    iconUrl: '/file-pdf.svg',
    title: 'Протокол.pdf',
    meta: 'Вер. 2 · 10.08.2026 12:34',
    metaTitle: 'Версия 2 · 1 МБ · 10.08.2026 12:34',
    openLabel: 'Открыть Протокол.pdf',
    downloadLabel: 'Скачать Протокол.pdf',
    onOpen,
    onDownload,
  })
  app.mount(root)
  mounted = { app, root }

  expect(root.querySelector('.shlz-file-row__visual img').getAttribute('src')).toBe('/file-pdf.svg')
  expect(root.querySelector('.shlz-file-row__meta').title).toBe('Версия 2 · 1 МБ · 10.08.2026 12:34')
  root.querySelector('.shlz-file-row__primary').click()
  root.querySelector('.shlz-file-row__action').click()
  await nextTick()
  expect(onOpen).toHaveBeenCalledOnce()
  expect(onDownload).toHaveBeenCalledOnce()
})
