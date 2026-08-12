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
  it('ignores list, dashboard and notification responses that arrive after deactivation', async () => {
    const lifecycle = createRequestRegistryLoadLifecycle()
    const state = { registry: 'current registry', dashboard: 'current dashboard', notifications: 'current notifications' }
    const registryResponse = deferred()
    const dashboardResponse = deferred()
    const notificationResponse = deferred()
    const notificationCursorWrites = []
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
    const notificationToken = lifecycle.notificationGuard.begin(true)
    const notificationUpdate = notificationResponse.promise.then(value => {
      if (!lifecycle.notificationGuard.isCurrent(notificationToken, true)) return
      state.notifications = value
      notificationCursorWrites.push(value)
    })

    lifecycle.deactivate()
    registryResponse.resolve('closed registry response')
    dashboardResponse.resolve('closed dashboard response')
    notificationResponse.resolve('closed notification response')
    await Promise.all([registryUpdate, dashboardUpdate, notificationUpdate])

    expect(state).toEqual({ registry: 'current registry', dashboard: 'current dashboard', notifications: 'current notifications' })
    expect(notificationCursorWrites).toEqual([])
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

  it('keeps newer same-activation responses when older requests finish last', async () => {
    const lifecycle = createRequestRegistryLoadLifecycle()
    const state = { registry: '', dashboard: '', notifications: '' }
    const cases = [
      ['registry', lifecycle.registryGuard],
      ['dashboard', lifecycle.dashboardGuard],
      ['notifications', lifecycle.notificationGuard],
    ]

    for (const [key, guard] of cases) {
      const older = deferred()
      const newer = deferred()
      const olderUpdate = applyWhenCurrent(guard, guard.begin(true), older.promise, state, key)
      const newerUpdate = applyWhenCurrent(guard, guard.begin(true), newer.promise, state, key)

      newer.resolve(`new ${key}`)
      await newerUpdate
      expect(state[key]).toBe(`new ${key}`)

      older.resolve(`old ${key}`)
      await olderUpdate
      expect(state[key]).toBe(`new ${key}`)
    }
  })

  it('cancels a scheduled reload and creation side effects on deactivation', async () => {
    const scheduled = new Map()
    let timerId = 0
    const lifecycle = createRequestRegistryLoadLifecycle({
      setTimeoutFn(callback) {
        scheduled.set(++timerId, callback)
        return timerId
      },
      clearTimeoutFn(id) {
        scheduled.delete(id)
      },
    })
    const effects = []
    lifecycle.scheduleReload(() => effects.push('list'))
    const createResponse = deferred()
    const createToken = lifecycle.createRequestGuard.begin(true)
    const creation = createResponse.promise.then(() => {
      if (!lifecycle.createRequestGuard.isCurrent(createToken, true)) return
      effects.push('list', 'dashboard', 'select-request')
    })

    lifecycle.deactivate()
    createResponse.resolve()
    await creation
    for (const callback of scheduled.values()) callback()

    expect(effects).toEqual([])
  })
})
