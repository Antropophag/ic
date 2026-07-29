import { reactive } from 'vue'

export function createConfirmDialog() {
  const state = reactive({ open: false, message: '', confirmLabel: 'Подтвердить', danger: false })
  let resolveCurrent = null

  function ask(message, { confirmLabel = 'Подтвердить', danger = false } = {}) {
    state.open = true
    state.message = message
    state.confirmLabel = confirmLabel
    state.danger = danger
    return new Promise(resolve => {
      resolveCurrent = resolve
    })
  }

  function settle(result) {
    state.open = false
    const resolve = resolveCurrent
    resolveCurrent = null
    resolve?.(result)
  }

  return {
    state,
    ask,
    accept: () => settle(true),
    cancel: () => settle(false),
  }
}
