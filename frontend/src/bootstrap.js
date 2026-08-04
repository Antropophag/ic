export async function bootstrapApplication({ loadDevelopmentTools, startApplication }) {
  if (loadDevelopmentTools) await loadDevelopmentTools()
  return startApplication()
}

export function developmentToolsLoader(browserWindow, document, importTools) {
  return async () => {
    const { startDevelopmentTools } = await importTools()
    startDevelopmentTools(browserWindow, document)
  }
}
