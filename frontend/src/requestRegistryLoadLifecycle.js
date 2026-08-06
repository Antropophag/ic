import { createLatestRequestGuard } from './latestRequestGuard'

export function createRequestRegistryLoadLifecycle() {
  const registryGuard = createLatestRequestGuard()
  const dashboardGuard = createLatestRequestGuard()

  return {
    registryGuard,
    dashboardGuard,
    deactivate() {
      registryGuard.invalidate()
      dashboardGuard.invalidate()
    },
  }
}
