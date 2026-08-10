import { createApp } from 'vue'
import '@fontsource/fira-sans/cyrillic-400.css'
import '@fontsource/fira-sans/cyrillic-500.css'
import '@fontsource/fira-sans/cyrillic-600.css'
import App from './App.vue'
import './styles.css'
import './admin.css'
import { bootstrapApplication, developmentToolsLoader } from './bootstrap'
import { resolveShlzMode, uiModeKey } from './uiMode'

const shlzMode = resolveShlzMode({ mode: import.meta.env.MODE, search: window.location.search })

const loadDevelopmentTools = import.meta.env.MODE === 'development'
  ? developmentToolsLoader(window, document, () => import('../dev/dev-tools.js'))
  : null

bootstrapApplication({
  loadDevelopmentTools,
  loadExperimentalUi: shlzMode ? () => import('../dev/shlz-demo.js') : null,
  startApplication: experimentalUi => {
    const app = createApp(App, { shlzMode })
    app.provide(uiModeKey, {
      shlz: shlzMode,
      ...(experimentalUi?.createUiMode?.() || {}),
    })
    return app.mount('#app')
  },
})
