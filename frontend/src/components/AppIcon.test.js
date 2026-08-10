// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import { uiModeKey } from '../uiMode'
import AppIcon from './AppIcon.vue'

let mounted

afterEach(() => {
  mounted?.app.unmount()
  mounted?.root.remove()
  mounted = undefined
})

function mountIcon(props, uiMode) {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp({ render: () => h(AppIcon, props) })
  app.provide(uiModeKey, uiMode)
  app.mount(root)
  mounted = { app, root }
  return root.querySelector('svg')
}

describe('AppIcon UI isolation', () => {
  it('uses a SHLZ sprite only when the caller explicitly opts in', () => {
    const icon = mountIcon(
      { name: 'help', size: 24, shlz: true },
      { shlz: true, iconSpriteUrl: '/sprite.svg' },
    )

    expect(icon.style.inlineSize).toBe('24px')
    expect(icon.style.blockSize).toBe('24px')
    expect(icon.querySelector('use').getAttribute('href')).toBe(
      '/sprite.svg#shlz-icon-info-circle',
    )
  })

  it('preserves the default inline icon without explicit opt-in', () => {
    const icon = mountIcon(
      { name: 'help', size: 18 },
      { shlz: true, iconSpriteUrl: '/sprite.svg' },
    )

    expect(icon.querySelector('use')).toBeNull()
    expect(icon.getAttribute('stroke')).toBe('currentColor')
  })
})
