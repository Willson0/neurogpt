<template>
  <main class="ans">
    <header class="ans-top">
      <div v-if="item" class="ans-meta">
        <span class="ans-model">{{ item.model }}</span>
        <span v-if="when" class="ans-when">{{ when }}</span>
      </div>
      <span v-else />
      <!-- a quiet way into the main app -->
      <a class="ans-home" href="/" @click.prevent="home">NeuroGPT <span aria-hidden="true">›</span></a>
    </header>

    <div v-if="loading" class="ans-skel" aria-busy="true" aria-label="Загружаем ответ">
      <i style="width:72%" /><i /><i style="width:88%" /><i style="width:54%" />
    </div>

    <section v-else-if="error" class="ans-empty">
      <h1>{{ error.title }}</h1>
      <p>{{ error.text }}</p>
    </section>

    <template v-else>
      <details v-if="item.prompt" class="ans-prompt">
        <summary>Запрос</summary>
        <p>{{ item.prompt }}</p>
      </details>
      <!-- eslint-disable-next-line vue/no-v-html : markdown-it with html:false, raw HTML of the answer is escaped -->
      <article class="ans-body" @click="onClick" v-html="html" />
      <footer class="ans-foot">
        <button type="button" class="ans-copy-all" @click="copyAll">{{ copiedAll ? 'Скопировано' : 'Скопировать ответ' }}</button>
      </footer>
    </template>
  </main>
</template>

<script>
import { api } from './services/api'
import { tg } from './services/telegram'
import { createRenderer } from './services/markdown'

// the renderer (markdown-it with KaTeX / highlight.js when the answer needs them); outside data, it is not reactive
let renderer = null

// /answer?id=<query_logs.id>[&sig=<hmac>]: the bot puts this link on a button under its reply.
export default {
  name: 'AnswerApp',
  data: () => ({ item: null, html: '', loading: true, error: null, copiedAll: false }),
  computed: {
    when() {
      if (!this.item?.created_at) return ''
      return new Date(this.item.created_at).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    },
  },
  async mounted() {
    const q = new URLSearchParams(location.search)
    // a web_app button may also pass the id as the Mini App start parameter: answer-<id>
    const id = Number(q.get('id') || (/^answer-(\d+)$/.exec(tg.startParam || '') || [])[1])
    if (!id) return this.fail('Ответ не найден', 'В ссылке нет номера ответа.')
    try {
      const item = (await api.answer(id, q.get('sig'))).data
      renderer = await createRenderer(item.answer)
      this.html = renderer.render(item.answer)
      this.item = item
      document.title = `${this.item.model} · NeuroGPT`
    } catch (e) {
      if (e.status === 401) this.fail('Откройте из Telegram', 'Этот ответ открывается кнопкой под сообщением бота.')
      else if (e.status === 404 || e.status === 403) this.fail('Ответ не найден', 'Возможно, ссылка устарела или ответ принадлежит другому человеку.')
      else this.fail('Не получилось загрузить', 'Проверьте интернет и откройте ссылку ещё раз.')
    } finally {
      this.loading = false
    }
  },
  methods: {
    fail(title, text) { this.error = { title, text }; this.loading = false },
    async onClick(e) {
      const btn = e.target.closest('[data-copy]')
      if (btn) {
        const ok = await tg.copy(renderer.code(Number(btn.dataset.copy)))
        tg.haptic(ok ? 'success' : 'error')
        btn.textContent = ok ? 'Скопировано' : 'Не вышло'
        btn.classList.toggle('done', ok)
        clearTimeout(btn._t)
        btn._t = setTimeout(() => { btn.textContent = 'Копировать'; btn.classList.remove('done') }, 1600)
        return
      }
      const a = e.target.closest('a[href]')
      if (a && tg.isTelegram && /^https?:/i.test(a.href)) {
        e.preventDefault()
        tg.openLink(a.href)
      }
    },
    async copyAll() {
      const ok = await tg.copy(this.item.answer)
      tg.haptic(ok ? 'success' : 'error')
      this.copiedAll = ok
      setTimeout(() => { this.copiedAll = false }, 1600)
    },
    // Telegram keeps the sign-in (initData) in sessionStorage, so the main app opens signed in
    home() { location.href = '/' },
  },
}
</script>
