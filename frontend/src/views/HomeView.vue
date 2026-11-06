<template>
  <div class="view" :data-view="'home'">
    <div class="topbar">
      <BalanceButton />
      <button type="button" :class="['plan', 'glass', app.plan === 'pro' ? 'pro' : '']" @click="app.push('plans')">
        <svg class="ic-s" aria-hidden="true"><use href="#i-crown" /></svg><span>{{ app.planName }}</span>
      </button>
    </div>

    <div v-if="expiresIn !== null" class="banner glass">
      <Sticker name="hourglass" :size="32" />
      <span>{{ app.planName }} закончится через {{ expiresIn }} {{ plural(expiresIn, 'день', 'дня', 'дней') }}</span>
      <button type="button" class="pill-btn" @click="app.push('plans')">Продлить</button>
    </div>

    <h1 class="hero-h">Что создадим?<small>Напишите идею, остальное сделаем сами</small></h1>

    <LensBar :active="app.mode" label="Что создаём">
      <button v-for="m in modes" :key="m.id" type="button" role="tab" :aria-selected="String(app.mode === m.id)" @click="app.setMode(m.id)">{{ m.label }}</button>
    </LensBar>

    <div v-if="app.mode !== 'tools'" class="prompt glass">
      <label for="prompt" class="visually-hidden">Запрос</label>
      <textarea id="prompt" v-model="app.prompt" name="prompt" rows="3" maxlength="4000" :placeholder="placeholders[app.mode]" @input="app.kick(0.75)" @focus="app.kick(0.6)" />
      <div class="prompt-row">
        <label v-if="app.mode === 'image'" :class="['pill-btn', 'icon-only']" :aria-label="app.refFile ? 'Референс: ' + app.refFile.name : 'Добавить референс'" style="cursor:pointer">
          <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" @change="pickRef">
          <svg class="ic-s" aria-hidden="true"><use :href="app.refFile ? '#i-check' : '#i-clip'" /></svg>
        </label>
        <button v-if="app.mode === 'image' || app.mode === 'video'" type="button" class="pill-btn" aria-label="Формат" @click="app.cycleFormat()">
          <i class="frame-ico" :style="{ width: app.format.w + 'px', height: app.format.h + 'px' }" /><span>{{ app.format.ratio }}</span>
        </button>
        <button v-if="app.mode === 'video'" type="button" class="pill-btn" @click="app.toggleDuration()">
          <svg class="ic-s" aria-hidden="true"><use href="#i-timer" /></svg><span>{{ app.duration }} секунд</span>
        </button>
        <span class="grow" />
        <button type="button" class="pill-btn hot" @click="improve"><svg class="ic-s" aria-hidden="true"><use href="#i-wand" /></svg>Улучшить</button>
      </div>
    </div>

    <ul v-if="app.mode !== 'tools'" class="list glass">
      <li>
        <button type="button" class="row" @click="app.openSheet('models')">
          <span class="sq hot"><svg class="ic-s" aria-hidden="true"><use href="#i-bolt" /></svg></span>
          <span class="t"><b>{{ app.model.name }}</b><span>{{ app.model.hint }}</span></span>
          <span class="end"><span class="num">{{ tok(app.cost) }}</span><svg class="chev" aria-hidden="true"><use href="#i-chev" /></svg></span>
        </button>
      </li>
      <li v-if="app.mode === 'image'">
        <button type="button" class="row" @click="app.openSheet('more')">
          <span class="sq"><svg class="ic-s" aria-hidden="true"><use href="#i-more" /></svg></span>
          <span class="t"><b>Настройки</b><span>{{ app.count }} {{ plural(app.count, 'вариант', 'варианта', 'вариантов') }}, стиль {{ app.style }}</span></span>
          <span class="end"><svg class="chev" aria-hidden="true"><use href="#i-chev" /></svg></span>
        </button>
      </li>
    </ul>

    <ul v-else class="list glass in-fade">
      <li v-for="t in app.tools" :key="t.id">
        <button type="button" class="row" @click="tool(t)">
          <span class="sq"><svg class="ic-s" aria-hidden="true"><use :href="'#i-' + toolIcons[t.id]" /></svg></span>
          <span class="t"><b>{{ t.name }}</b><span>{{ t.hint }}</span></span>
          <span class="end num">{{ tok(t.cost) }}</span>
        </button>
      </li>
    </ul>

    <div v-if="app.trends.length" class="group" style="display:grid;gap:var(--space-sm)">
      <div class="sec-head"><h2>В тренде</h2></div>
      <div class="trends">
        <button v-for="p in app.trends" :key="p.id" type="button" class="trend" @click="useTrend(p)">
          <ArtCanvas :src="p.cover_url" :seed="100 + p.id * 17" :palette="p.palette" :w="136" :h="176" />
          <span><b>{{ p.name }}</b>{{ p.trend_hint }}</span>
        </button>
      </div>
    </div>

    <div v-if="recent.length" class="group" style="display:grid;gap:var(--space-sm)">
      <div class="sec-head"><h2>Недавние</h2></div>
      <ul class="list glass">
        <li v-for="r in recent" :key="r.key">
          <button type="button" class="row" @click="r.open()">
            <span class="sq"><svg class="ic-s" aria-hidden="true"><use :href="'#i-' + r.icon" /></svg></span>
            <span class="t"><b>{{ r.title }}</b><span>{{ r.sub }}</span></span>
            <span class="end"><svg class="chev" aria-hidden="true"><use href="#i-chev" /></svg></span>
          </button>
        </li>
      </ul>
    </div>

    <NewsDeck />
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { api } from '../services/api'
import { plural, tok } from '../services/format'
import { tg } from '../services/telegram'
import BalanceButton from '../components/BalanceButton.vue'
import LensBar from '../components/LensBar.vue'
import Sticker from '../components/Sticker.vue'
import ArtCanvas from '../components/ArtCanvas.vue'
import NewsDeck from '../components/NewsDeck.vue'

const ago = (iso) => {
  const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  return d <= 0 ? 'сегодня' : d === 1 ? 'вчера' : `${d} ${plural(d, 'день', 'дня', 'дней')} назад`
}

export default {
  name: 'HomeView',
  components: { BalanceButton, LensBar, Sticker, ArtCanvas, NewsDeck },
  data: () => ({
    chats: [],
    modes: [
      { id: 'text', label: 'Текст' }, { id: 'image', label: 'Фото' }, { id: 'video', label: 'Видео' }, { id: 'tools', label: 'Ещё' },
    ],
    placeholders: {
      text: 'Письмо, идея, код, перевод: спросите что угодно',
      image: 'Кот-космонавт в стиле акварели',
      video: 'Волны на закате, камера летит вдоль берега',
    },
    toolIcons: { upscale: 'upscale', remove_bg: 'eraser', animate: 'video' },
  }),
  computed: {
    app() { return useApp() },
    expiresIn() {
      const u = this.app.user
      if (!u || u.plan === 'free' || !u.plan_expires_at) return null
      const days = Math.ceil((new Date(u.plan_expires_at).getTime() - Date.now()) / 86400000)
      return days <= 3 ? Math.max(days, 0) : null
    },
    recent() {
      const items = [
        ...this.app.recent.filter((g) => g.status === 'done' || g.status === 'processing' || g.status === 'queued').map((g) => ({
          key: 'g' + g.id, at: g.created_at, icon: g.kind === 'video' ? 'video' : 'image',
          title: g.prompt || 'Генерация', sub: `${g.kind === 'video' ? 'Видео' : 'Фото'}, ${ago(g.created_at)}`, open: () => this.app.openResult(g),
        })),
        ...this.chats.map((c) => ({
          key: 'c' + c.id, at: c.updated_at, icon: 'text', title: c.title || 'Чат', sub: `Чат, ${ago(c.updated_at)}`, open: () => this.app.openChatById(c.id),
        })),
      ]
      return items.sort((a, b) => (a.at < b.at ? 1 : -1)).slice(0, 4)
    },
  },
  async mounted() {
    try { this.chats = (await api.chats()).slice(0, 3) } catch { /* optional block */ }
  },
  methods: {
    plural, tok,
    improve(e) { this.app.improvePrompt() },
    pickRef(e) { this.app.refFile = e.target.files[0] || null; if (this.app.refFile) tg.haptic('light') },
    tool(t) {
      tg.haptic('light')
      if (this.app.balance < t.cost) return this.app.openSheet('need', { lack: t.cost - this.app.balance })
      this.app.openSheet('tool', { tool: t.id })
    },
    useTrend(p) {
      this.app.useTemplate(p.prompt)
      this.$nextTick(() => document.getElementById('prompt')?.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    },
  },
}
</script>
