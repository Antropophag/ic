export function createShlzTooltipLifecycle({ enabled, root, enhance, afterRender }) {
  let controllers = []
  let generation = 0

  function destroyControllers(items = controllers) {
    items.forEach(controller => controller.destroy())
  }

  async function refresh() {
    const refreshGeneration = ++generation
    destroyControllers()
    controllers = []
    if (!enabled() || !root() || !enhance()) return
    await afterRender()
    const nextControllers = await enhance()(root())
    if (refreshGeneration !== generation) {
      destroyControllers(nextControllers)
      return
    }
    controllers = nextControllers
  }

  function destroy() {
    generation += 1
    destroyControllers()
    controllers = []
  }

  return { destroy, refresh }
}
