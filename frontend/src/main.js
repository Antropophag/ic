import { createApp } from 'vue'
import '@fontsource/fira-sans/cyrillic-400.css'
import '@fontsource/fira-sans/cyrillic-500.css'
import '@fontsource/fira-sans/cyrillic-600.css'
import App from './App.vue'
import './vendor/shlz/tokens.css'
import './vendor/shlz/status-badge.css'
import './vendor/shlz/table.css'
import './vendor/shlz/popover.css'
import './vendor/shlz/choice.css'
import './vendor/shlz/button.css'
import './vendor/shlz/notification.css'
import './vendor/shlz/file-row.css'
import './vendor/shlz/file-upload.css'
import './styles.css'
import './admin.css'
import { bootstrapApplication, developmentToolsLoader } from './bootstrap'

const loadDevelopmentTools = import.meta.env.MODE === 'development'
  ? developmentToolsLoader(window, document, () => import('../dev/dev-tools.js'))
  : null

void bootstrapApplication({
  loadDevelopmentTools,
  startApplication: () => createApp(App).mount('#app'),
})
