import '@fontsource/golos-text/cyrillic-400.css'
import '@fontsource/golos-text/cyrillic-500.css'
import '@fontsource/golos-text/cyrillic-600.css'
import '@shlz/styles'
import iconSpriteUrl from '@shlz/icons/sprite.svg?url'
import './shlz-demo.css'

export async function enhanceRegistryTooltips(scope) {
  const { enhanceTooltips } = await import('@shlz/behaviors/tooltip')
  return enhanceTooltips(scope)
}

export function createUiMode() {
  return { iconSpriteUrl, enhanceRegistryTooltips }
}
