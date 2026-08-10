// @vitest-environment happy-dom

import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import RegistryPagination from './RegistryPagination.vue'

const mountedApps = []

function mountPagination(props = {}) {
  const root = document.createElement('div')
  document.body.append(root)
  const onPage = vi.fn()
  const onPageSize = vi.fn()
  const app = createApp(RegistryPagination, {
    page: 1,
    pageCount: 3,
    pageNumbers: [1, 2, 3],
    pageSize: 20,
    pageSizes: [20, 50, 100],
    total: 51,
    onPage,
    onPageSize,
    ...props,
  })
  app.mount(root)
  mountedApps.push({ app, root })
  return { root, onPage, onPageSize }
}

afterEach(() => {
  mountedApps.splice(0).forEach(({ app, root }) => {
    app.unmount()
    root.remove()
  })
})

describe('registry SHLZ pagination', () => {
  it('uses the current public pagination semantics', () => {
    const { root } = mountPagination()
    const navigation = root.querySelector('nav.shlz-pagination')

    expect(navigation.getAttribute('aria-label')).toBe('Страницы реестра заявок')
    expect(navigation.querySelector('li:first-child > span[aria-disabled="true"]')).not.toBeNull()
    expect(navigation.querySelector('a[aria-current="page"]').textContent).toBe('1')
    expect(navigation.querySelector('button[aria-pressed="true"]').textContent).toBe('20')
    expect(navigation.querySelector('.shlz-pagination__summary').textContent).toBe('1–20 из 51')
  })

  it('emits application pagination intents', async () => {
    const { root, onPage, onPageSize } = mountPagination({ page: 2 })
    root.querySelector('a[aria-label="Следующая страница"]').click()
    root.querySelector('button:last-child').click()
    await nextTick()

    expect(onPage).toHaveBeenCalledWith(3)
    expect(onPageSize).toHaveBeenCalledWith(100)
  })
})
