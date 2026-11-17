// The answer page (answer.html, served at /answer?id=<query_logs.id>): one AI answer from the bot, rendered as
// Markdown with LaTeX and code blocks. A page of its own, so the Mini App's screens and the Markdown/KaTeX code
// never load each other.
import { createApp } from 'vue'
import '@fontsource/onest/cyrillic-400.css'
import '@fontsource/onest/cyrillic-600.css'
import '@fontsource/onest/latin-400.css'
import '@fontsource/onest/latin-600.css'
import '@fontsource/unbounded/cyrillic-600.css'
import '@fontsource/unbounded/latin-600.css'
import './styles/app.css'
import './styles/answer.css'
import AnswerApp from './AnswerApp.vue'
import { tg } from './services/telegram'
import { theme } from './services/theme'

document.documentElement.classList.add('js', 'ans-page')
theme.restore()
tg.init()

createApp(AnswerApp).mount('#app')
