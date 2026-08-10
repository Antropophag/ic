import { inject } from 'vue'

export const uiModeKey = Symbol('ic-ui-mode')

export function resolveShlzMode({ mode, search }) {
  return mode === 'development' && new URLSearchParams(search).get('ui') === 'shlz'
}

export function useUiMode() {
  return inject(uiModeKey, { shlz: false })
}
