// The admin panel is a page of its own (admin.html, served at /admin): it loads without the Mini App's screens,
// the animated background and the sheets, so a long table or form stays light even on a weak device.
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource/onest/cyrillic-400.css'
import '@fontsource/onest/cyrillic-600.css'
import '@fontsource/onest/latin-400.css'
import '@fontsource/onest/latin-600.css'
import '@fontsource/unbounded/cyrillic-600.css'
import '@fontsource/unbounded/latin-600.css'
import './styles/app.css'
import './styles/admin.css'
import AdminApp from './AdminApp.vue'
import { tg } from './services/telegram'
import { dragScroll } from './services/dragScroll'
import { theme } from './services/theme'

document.documentElement.classList.add('js', 'adm-page')
theme.restore()
tg.init()

createApp(AdminApp).use(createPinia()).directive('drag-scroll', dragScroll).mount('#app')
