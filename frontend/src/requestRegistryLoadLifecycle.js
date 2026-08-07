import { createLatestRequestGuard } from './latestRequestGuard'

export function createRequestRegistryLoadLifecycle({
  setTimeoutFn = globalThis.setTimeout,
  clearTimeoutFn = globalThis.clearTimeout,
} = {}) {
  const registryGuard = createLatestRequestGuard()
  const dashboardGuard = createLatestRequestGuard()
  const notificationGuard = createLatestRequestGuard()
  const downloadGuard = createLatestRequestGuard()
  const createRequestGuard = createLatestRequestGuard()
  let reloadTimer = null

  return {
    registryGuard,
    dashboardGuard,
    notificationGuard,
    downloadGuard,
    createRequestGuard,
    scheduleReload(callback, delay = 300) {
      clearTimeoutFn(reloadTimer)
      reloadTimer = setTimeoutFn(callback, delay)
    },
    deactivate() {
      clearTimeoutFn(reloadTimer)
      reloadTimer = null
      registryGuard.invalidate()
      dashboardGuard.invalidate()
      notificationGuard.invalidate()
      downloadGuard.invalidate()
      createRequestGuard.invalidate()
    },
  }
}
