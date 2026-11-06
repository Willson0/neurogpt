<template>
  <div class="view" data-view="create">
    <div class="gen-head">
      <Sticker :name="headSticker" :size="48" />
      <span class="t"><h2>{{ title }}</h2><span>{{ eta }}</span></span>
    </div>
    <div v-if="gen" :style="gen.items.length > 1 ? 'display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--space-xs)' : ''">
      <ResultStage v-for="(it, i) in gen.items" :key="it.id" :item="it" :ar="gen.ar" :selected="gen.items.length > 1 && sel === i"
                   @click="sel = i" @revealed="revealed[i] = true" />
    </div>
    <div v-if="showActions" class="actions-wrap" style="display:grid;gap:var(--space-md)">
      <div class="actions">
        <button type="button" class="act glass" style="--i:0" @click="download"><svg class="ic" aria-hidden="true"><use href="#i-down" /></svg>Скачать</button>
        <button type="button" class="act glass" style="--i:1" @click="story"><svg class="ic" aria-hidden="true"><use href="#i-story" /></svg>В сторис</button>
        <button type="button" class="act glass" style="--i:2" :aria-pressed="String(!!current.is_favorite)" @click="fav"><svg class="ic" aria-hidden="true"><use href="#i-heart" /></svg>Избранное</button>
        <button type="button" class="act glass" style="--i:3" @click="app.openSheet('resmore')"><svg class="ic" aria-hidden="true"><use href="#i-dots" /></svg>Ещё</button>
      </div>
      <div class="rate">
        <span>Нравится?</span>
        <button type="button" class="icon-btn" aria-label="Нравится" :aria-pressed="String(current.rating === 1)" @click="app.rate(current, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-like" /></svg></button>
        <button type="button" class="icon-btn" aria-label="Не нравится" :aria-pressed="String(current.rating === -1)" @click="app.rate(current, -1)"><svg class="ic-s" aria-hidden="true"><use href="#i-dislike" /></svg></button>
      </div>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { tok } from '../services/format'
import { tg } from '../services/telegram'
import { burstFrom } from '../services/bus'
import Sticker from '../components/Sticker.vue'
import ResultStage from '../components/ResultStage.vue'

export default {
  name: 'CreateView',
  components: { Sticker, ResultStage },
  data: () => ({ sel: 0, revealed: {} }),
  computed: {
    app() { return useApp() },
    gen() { return this.app.gen },
    current() { return this.gen?.items[this.sel] || this.gen?.items[0] || {} },
    isVideo() { return this.gen?.kind === 'video' },
    showActions() {
      const g = this.gen
      return !!g && g.done && !g.failed && this.current.status === 'done' && this.revealed[this.sel]
    },
    allQueued() { return this.gen?.items.every((i) => i.status === 'queued') },
    title() {
      const g = this.gen
      if (!g) return ''
      if (g.done) return g.failed ? 'Не получилось' : 'Готово'
      return this.isVideo ? 'Снимаем видео' : g.kind === 'tool' ? 'Обрабатываем' : 'Проявляем картинку'
    },
    eta() {
      const g = this.gen
      if (!g) return ''
      if (g.done) return g.failed ? 'Токены вернулись на баланс' : `Списано ${tok(g.cost)}, результат уже в чате`
      return this.allQueued ? 'Вы в очереди, обычно это недолго' : 'Работаем, можно свернуть: пришлём результат в чат'
    },
    headSticker() {
      const g = this.gen
      return g && g.done ? (g.failed ? 'cry' : 'party') : 'art'
    },
  },
  watch: {
    'gen.done'(d) { if (d && !this.gen.failed) this.$nextTick(() => burstFrom(this.$el.querySelector('.stage'), 26, 150)) },
  },
  methods: {
    tok,
    download() {
      tg.haptic('light')
      const url = this.current.result_url
      if (!url) return
      tg.download(url, `neurogpt-${this.current.id}.${url.split('.').pop().split('?')[0]}`)
      this.app.toast('Сохраняем на устройство')
    },
    story() {
      tg.haptic('light')
      const url = this.current.result_url
      if (!tg.shareStory(url)) this.app.toast('Историю можно выложить из чата с ботом')
    },
    fav(e) {
      tg.haptic('light')
      this.app.toggleFavorite(this.current)
      if (!this.current.is_favorite) burstFrom(e.currentTarget, 10, 50)
    },
  },
}
</script>
