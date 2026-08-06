import { describe, expect, it } from 'vitest'
import { createRequestRegistryLoadLifecycle } from './requestRegistryLoadLifecycle'

function deferred() {
  let resolve
  const promise = new Promise(done => { resolve = done })
  return { promise, resolve }
}

async function applyWhenCurrent(guard, token, response, state, key) {
  const value = await response
  if (guard.isCurrent(token, true)) state[key] = value
}

describe('request registry load lifecycle', () => {
  it('ignores list and dashboard responses that arrive after deactivation', async () => {
    const lifecycle = createRequestRegistryLoadLifecycle()
    const state = { registry: 'current registry', dashboard: 'current dashboard' }
    const registryResponse = deferred()
    const dashboardResponse = deferred()
    const registryUpdate = applyWhenCurrent(
      lifecycle.registryGuard,
      lifecycle.registryGuard.begin(true),
      registryResponse.promise,
      state,
      'registry',
    )
    const dashboardUpdate = applyWhenCurrent(
      lifecycle.dashboardGuard,
      lifecycle.dashboardGuard.begin(true),
      dashboardResponse.promise,
      state,
      'dashboard',
    )

    lifecycle.deactivate()
    registryResponse.resolve('closed registry response')
    dashboardResponse.resolve('closed dashboard response')
    await Promise.all([registryUpdate, dashboardUpdate])

    expect(state).toEqual({ registry: 'current registry', dashboard: 'current dashboard' })
  })

  it('ignores responses from an earlier activation after reopening', async () => {
    const lifecycle = createRequestRegistryLoadLifecycle()
    const state = { registry: '', dashboard: '' }
    const oldRegistry = deferred()
    const oldDashboard = deferred()
    const oldUpdates = [
      applyWhenCurrent(lifecycle.registryGuard, lifecycle.registryGuard.begin(true), oldRegistry.promise, state, 'registry'),
      applyWhenCurrent(lifecycle.dashboardGuard, lifecycle.dashboardGuard.begin(true), oldDashboard.promise, state, 'dashboard'),
    ]

    lifecycle.deactivate()
    const newRegistry = deferred()
    const newDashboard = deferred()
    const newUpdates = [
      applyWhenCurrent(lifecycle.registryGuard, lifecycle.registryGuard.begin(true), newRegistry.promise, state, 'registry'),
      applyWhenCurrent(lifecycle.dashboardGuard, lifecycle.dashboardGuard.begin(true), newDashboard.promise, state, 'dashboard'),
    ]
    newRegistry.resolve('new registry')
    newDashboard.resolve('new dashboard')
    await Promise.all(newUpdates)

    oldRegistry.resolve('old registry')
    oldDashboard.resolve('old dashboard')
    await Promise.all(oldUpdates)

    expect(state).toEqual({ registry: 'new registry', dashboard: 'new dashboard' })
  })
})
