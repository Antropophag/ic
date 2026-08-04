import { createApp } from 'vue'
import '@fontsource/fira-sans/cyrillic-400.css'
import '@fontsource/fira-sans/cyrillic-500.css'
import '@fontsource/fira-sans/cyrillic-600.css'
import App from './App.vue'
import './styles.css'
import './admin.css'
import { bootstrapApplication, developmentToolsLoader } from './bootstrap'

const loadDevelopmentTools = import.meta.env.MODE === 'development'
  ? developmentToolsLoader(window, document, () => import('../dev/dev-tools.js'))
  : null

bootstrapApplication({
  loadDevelopmentTools,
  startApplication: () => createApp(App).mount('#app'),
})
