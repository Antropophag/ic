export async function bootstrapApplication({ loadDevelopmentTools, startApplication }) {
  if (loadDevelopmentTools) await loadDevelopmentTools()
  return startApplication()
}
