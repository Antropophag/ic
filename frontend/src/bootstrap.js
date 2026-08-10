export async function bootstrapApplication({ loadDevelopmentTools, loadExperimentalUi, startApplication }) {
  if (loadDevelopmentTools) await loadDevelopmentTools()
  const experimentalUi = loadExperimentalUi ? await loadExperimentalUi() : null
  return startApplication(experimentalUi)
}

export function developmentToolsLoader(browserWindow, document, importTools) {
  return async () => {
    const { startDevelopmentTools } = await importTools()
    startDevelopmentTools(browserWindow, document)
  }
}
