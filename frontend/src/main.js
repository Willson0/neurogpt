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

document.documentElement.classList.add('js')
tg.init()

createApp(App).use(createPinia()).directive('drag-scroll', dragScroll).mount('#app')
