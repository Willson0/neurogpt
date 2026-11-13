import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource/onest/cyrillic-400.css'
import '@fontsource/onest/cyrillic-600.css'
import '@fontsource/onest/latin-400.css'
import '@fontsource/onest/latin-600.css'
import '@fontsource/unbounded/cyrillic-600.css'
import '@fontsource/unbounded/latin-600.css'
import './styles/app.css'
import App from './App.vue'
import { tg } from './services/telegram'
import { dragScroll } from './services/dragScroll'

import { theme } from './services/theme'

import { adminLink } from './stores/app'

// /admin is its own page (admin.html); a server that sent index.html for it gets redirected there
if (adminLink()) location.replace('/admin.html' + location.search)

document.documentElement.classList.add('js')
theme.restore()
tg.init()

createApp(App).use(createPinia()).directive('drag-scroll', dragScroll).mount('#app')
