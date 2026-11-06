<template>
  <div ref="bottom" class="bottom">
    <div :class="['dockrow', isRoot ? '' : 'away']">
      <LensBar :active="app.tab" extra="dock" role="navigation" label="Разделы">
        <button v-for="t in tabs" :key="t.id" type="button" :aria-current="app.tab === t.id ? 'page' : null" @click="onTab(t.id)">
          <svg class="ic" aria-hidden="true"><use :href="'#i-' + t.icon" /></svg>{{ t.label }}
        </button>
      </LensBar>
      <button ref="cta" type="button" :class="['cta', ctaOpen ? 'open' : '']" :aria-label="ctaAria" @click="onCta">
        <span class="fab"><svg aria-hidden="true"><use href="#i-spark" /></svg></span>
        <span class="lbl">
          <template v-if="app.current === 'home' && hasPrompt && app.mode !== 'tools'">
            <template v-if="short">Пополнить и создать</template>
            <template v-else>{{ app.mode === 'text' ? 'Спросить' : 'Создать' }}<span class="price"><svg class="token" aria-hidden="true"><use href="#i-token" /></svg><span class="num">{{ fmt(app.cost) }}</span></span></template>
          </template>
          <template v-else-if="emptyGallery">Создать первую</template>
          <template v-else>Создать</template>
        </span>
      </button>
    </div>

    <!-- In-page fallback for plain browsers and old clients; Telegram uses its native buttons. -->
    <div v-if="!native" :class="['footbar', foot ? '' : 'away']">
      <button type="button" :class="['bigbtn', foot && foot.gold ? 'gold' : '', foot && foot.shine ? 'shine' : '']" :disabled="foot && foot.busy" @click="foot && foot.onMain()">
        <span class="lbl">{{ foot ? foot.main : '' }}</span>
      </button>
      <button v-if="foot && foot.sec" type="button" :class="['ghostbtn', foot.secDanger ? 'danger' : '']" @click="foot.onSec()">{{ foot.sec }}</button>
    </div>

    <form v-if="app.current === 'chat'" class="composer glass" @submit.prevent="send">
      <label for="chatInput" class="visually-hidden">Сообщение</label>
      <input id="chatInput" v-model="text" name="message" autocomplete="off" :placeholder="`Сообщение, ${app.textModel.price} ${app.textModel.price === 1 ? 'токен' : 'токена'}`">
      <button type="submit" class="send" aria-label="Отправить" :disabled="app.chat.busy"><svg class="ic-s" aria-hidden="true"><use href="#i-send" /></svg></button>
    </form>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import LensBar from './LensBar.vue'
import { fmt } from '../services/format'
import { tg } from '../services/telegram'

const ROOT = ['home', 'gallery', 'profile']

export default {
  name: 'BottomBar',
  components: { LensBar },
  data: () => ({ text: '', tabs: [
    { id: 'home', icon: 'home', label: 'Главная' },
    { id: 'gallery', icon: 'image', label: 'Фотосеты' },
    { id: 'profile', icon: 'user', label: 'Профиль' },
  ] }),
  computed: {
    app() { return useApp() },
    isRoot() { return ROOT.includes(this.app.current) },
    hasPrompt() { return this.app.prompt.trim().length > 0 },
    short() { return this.app.balance < this.app.cost },
    emptyGallery() { return this.app.current === 'gallery' && !this.app.photosets.length },
    ctaOpen() { return (this.app.current === 'home' && this.app.mode !== 'tools' && this.hasPrompt) || this.emptyGallery },
    ctaAria() { return this.ctaOpen ? 'Создать' : 'Новая генерация' },
    native() { return tg.hasMainButton },
    /** What the primary/secondary action is on the current screen, or null when there is none. */
    foot() {
      const a = this.app
      const id = a.current
      if (id === 'create' && a.gen) {
        const g = a.gen
        if (g.done) {
          return g.failed
            ? { main: 'Назад', onMain: () => a.back() }
            : { main: 'Открыть чат с ботом', sec: 'Ещё вариант', onMain: () => (tg.isTelegram ? tg.close() : a.toast('Результат пришёл в чат с ботом')), onSec: () => this.again() }
        }
        const queued = g.items.every((i) => i.status === 'queued')
        return { main: 'Свернуть, пришлю в чат', sec: queued ? 'Отменить, токены вернутся' : null, secDanger: true, onMain: () => a.minimize(), onSec: () => a.cancelGeneration() }
      }
      if (id === 'topup') return { main: `Оплатить ${fmt(a.pack.price)} ₽`, shine: true, busy: a.busy, onMain: () => a.pay('topup') }
      if (id === 'plans' && a.tier !== 'free') {
        const p = a.plans[a.tier]
        if (!p) return null
        const price = a.period === 'month' ? p.month : p.year_month * 12
        return {
          main: `${a.plan === a.tier ? 'Продлить' : 'Оформить'} ${p.name} за ${fmt(price)} ₽`,
          gold: a.tier === 'pro', shine: true, busy: a.busy, onMain: () => a.pay('plan'),
        }
      }
      return null
    },
  },
  watch: {
    foot: { immediate: true, deep: false, handler() { this.syncNative() } },
    'app.busy'() { this.syncNative() },
    'app.current'() { this.$nextTick(this.measure) },
  },
  mounted() {
    this.ro = new ResizeObserver(this.measure)
    this.ro.observe(this.$refs.bottom)
  },
  beforeUnmount() { this.ro?.disconnect() },
  methods: {
    fmt,
    measure() {
      document.documentElement.style.setProperty('--bottom-h', `${this.$refs.bottom.offsetHeight}px`)
    },
    syncNative() {
      if (!this.native) return
      const f = this.foot
      if (!f) { tg.setMain({}); tg.setSecondary({}); return }
      tg.setMain({ text: f.main, onClick: f.onMain, active: !this.app.busy, shine: !!f.shine, ...(f.gold ? { color: '#E8B64C', textColor: '#2B1D05' } : {}) })
      f.sec ? tg.setSecondary({ text: f.sec, onClick: f.onSec, destructive: f.secDanger }) : tg.setSecondary({})
    },
    onTab(id) {
      this.app.goTab(id)
    },
    onCta() {
      const a = this.app
      if (a.current === 'home' && this.ctaOpen) {
        if (a.mode === 'text') return this.askText()
        return a.start()
      }
      if (this.emptyGallery) { a.goTab('home'); return }
      tg.haptic('light')
      if (a.current !== 'home') a.goTab('home')
      setTimeout(() => { document.getElementById('prompt')?.focus({ preventScroll: true }); a.kick(0.8) }, 260)
    },
    askText() {
      const a = this.app
      const q = a.prompt.trim()
      a.openChat()
      a.prompt = ''
      this.$nextTick(() => a.sendMessage(q))
    },
    again() {
      const a = this.app
      if (a.gen?.photoset) return a.toast('Выберите фотосет ещё раз')
      a.stack = ['home']; a.tab = 'home'
      a.start()
    },
    send() {
      const v = this.text.trim()
      if (!v) return
      this.text = ''
      this.app.sendMessage(v)
    },
  },
}
</script>
