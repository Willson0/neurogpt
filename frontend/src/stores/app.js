import { defineStore } from 'pinia'
import { api, ApiError } from '../services/api'
import { tg } from '../services/telegram'
import { tok, plural } from '../services/format'
import { bus, confetti } from '../services/bus'

const ROOT = ['home', 'gallery', 'profile']
const FORMATS = [
  { ratio: '1:1', ar: 1, w: 14, h: 14 },
  { ratio: '9:16', ar: 0.5625, w: 10, h: 17 },
  { ratio: '16:9', ar: 1.7778, w: 18, h: 10 },
]
const PLAN_RANK = { free: 0, plus: 1, pro: 2 }

export const useApp = defineStore('app', {
  state: () => ({
    booting: true,
    bootError: null,
    galleryError: false,
    user: null,
    models: { text: [], image: [], video: [] },
    photosets: [],
    news: [],
    packs: [],
    plans: {},
    tools: [],
    bonus: {},
    recent: [],
    botUsername: '',

    stack: ['home'],
    tab: 'home',
    sheet: null, // { name, props }
    toastState: null, // { id, text, sticker }

    // composer
    mode: 'image',
    modelSel: { text: 'auto', image: 'auto', video: 'auto' },
    fmtIndex: 0,
    duration: 5,
    count: 1,
    style: 'любой',
    negative: '',
    prompt: '',
    refFile: null,

    // generation screen
    gen: null, // { items: [...], done, failed, pendingCost, startedAt, photoset }
    // chat
    chat: { id: null, messages: [], busy: false },
    history: { items: [], loaded: false },
    // billing
    packIndex: 1,
    tier: 'pro',
    period: 'month',
    payment: null, // pending payment being watched
    busy: false,

    plasma: { target: 0.35, speed: 1 },
  }),

  getters: {
    current: (s) => s.stack[s.stack.length - 1],
    canGoBack: (s) => s.stack.length > 1,
    balance: (s) => s.user?.balance ?? 0,
    plan: (s) => s.user?.plan ?? 'free',
    planName() { return this.plans[this.plan]?.name || 'Free' },
    format: (s) => FORMATS[s.fmtIndex],
    formats: () => FORMATS,
    modelList: (s) => s.models[s.mode === 'tools' ? 'image' : s.mode] || [],
    model() {
      const list = this.models[this.mode === 'tools' ? 'image' : this.mode] || []
      return list.find((m) => m.id === this.modelSel[this.mode]) || list[0] || { id: 'auto', name: 'Авто', hint: '', price: 0 }
    },
    textModel: (s) => (s.models.text || []).find((m) => m.id === s.modelSel.text) || (s.models.text || [])[0] || { name: 'Авто', price: 1 },
    cost() {
      const m = this.model
      if (this.mode === 'video') return m.price * (this.duration === 10 ? 2 : 1)
      if (this.mode === 'image') return m.price * this.count
      return m.price
    },
    pack: (s) => s.packs[s.packIndex] || { tokens: 0, price: 0 },
    isLocked: (s) => (m) => (PLAN_RANK[s.plan] ?? 0) < (PLAN_RANK[m.min_plan] ?? 0),
    trends: (s) => s.photosets.filter((p) => p.trending),
  },

  actions: {
    // ---------- boot ----------
    async boot() {
      this.booting = true
      this.bootError = null
      try {
        const data = await api.bootstrap()
        this.applyBootstrap(data)
        const firstVisit = !localStorage.getItem('ng_seen')
        if (firstVisit) {
          try { localStorage.setItem('ng_seen', '1') } catch { /* private mode */ }
          if (data.user.balance > 0 && data.user.stats.created === 0) this.openSheet('first')
        }
        this.refreshDailyBonus()
      } catch (e) {
        this.bootError = e instanceof ApiError ? e : new ApiError(0, { code: 'network' })
      } finally {
        this.booting = false
      }
    },
    applyBootstrap(d) {
      this.user = d.user
      this.models = { text: [], image: [], video: [], ...d.models }
      this.photosets = d.photosets
      this.news = d.news
      this.packs = d.packs
      this.plans = d.plans
      this.tools = d.tools
      this.bonus = d.bonus
      this.recent = d.recent
      this.botUsername = d.bot_username || ''
      if (this.packIndex >= this.packs.length) this.packIndex = Math.min(1, this.packs.length - 1)
    },
    setUser(u) { if (u) this.user = u },
    async refreshUser() {
      try { this.setUser(await api.me()) } catch { /* keep the old value */ }
    },
    async refreshDailyBonus() {
      if (!this.user?.daily_bonus_available) return
      try {
        const r = await api.dailyBonus()
        this.setUser(r.user)
        if (r.granted) this.toast(`Ежедневный бонус: +${r.amount}`, 'party')
      } catch { /* not critical */ }
    },

    // ---------- navigation ----------
    show(id, push = true) {
      if (push) this.stack.push(id)
      if (ROOT.includes(id)) { this.tab = id; this.stack = [id] }
      this.transition = ROOT.includes(id) && ROOT.includes(this.stack[0]) && !push ? 'in-fade' : push ? 'in-push' : 'in-pop'
    },
    goTab(id) { this.tab = id; this.stack = [id]; this.transition = 'in-fade'; tg.haptic('selection') },
    push(id) { this.stack.push(id); this.transition = 'in-push'; tg.haptic('light') },
    back() {
      if (this.sheet) return this.closeSheet()
      if (this.stack.length > 1) {
        this.stack.pop()
        this.transition = 'in-pop'
        if (this.current === 'create') this.stopPolling()
      }
    },

    // ---------- sheets & toast ----------
    openSheet(name, props = {}) { this.sheet = { name, props } },
    closeSheet() { this.sheet = null },
    toast(text, sticker = null) { this.toastState = { id: Date.now() + Math.random(), text, sticker } },
    kick(v) { this.plasma.target = Math.min(1.25, Math.max(this.plasma.target, v)) },

    // ---------- composer ----------
    setMode(m) {
      this.mode = m
      tg.haptic('selection')
    },
    cycleFormat() { this.fmtIndex = (this.fmtIndex + 1) % FORMATS.length; tg.haptic('selection') },
    toggleDuration() { this.duration = this.duration === 5 ? 10 : 5; tg.haptic('selection') },
    selectModel(kind, m) {
      if (this.isLocked(m)) {
        tg.haptic('warning')
        this.closeSheet()
        this.tier = m.min_plan === 'plus' ? 'plus' : 'pro'
        setTimeout(() => this.push('plans'), 200)
        return
      }
      this.modelSel[kind] = m.id
      tg.haptic('selection')
      setTimeout(() => this.closeSheet(), 180)
    },
    improvePrompt() {
      const p = this.prompt.trim()
      if (!p) return this.toast('Сначала пара слов')
      this.prompt = p.replace(/\.$/, '') + ', детальная проработка, мягкий свет, высокое качество'
      this.kick(1)
      tg.haptic('light')
      this.toast('Запрос улучшен бесплатно', 'magic')
    },
    useTemplate(text) {
      this.prompt = text
      this.mode = 'image'
      this.kick(0.9)
      tg.haptic('light')
    },

    // ---------- generation ----------
    async start(extra = {}) {
      const photoset = extra.photoset || null
      const kind = this.mode === 'video' ? 'video' : 'image'
      const cost = photoset ? this.models.image.find((m) => m.id === this.modelSel.image)?.price ?? this.model.price : this.cost
      if (this.balance < cost) { tg.haptic('warning'); return this.openSheet('need', { lack: cost - this.balance }) }
      if (this.busy) return
      this.busy = true
      try {
        const body = photoset
          ? { kind: 'image', model: this.modelSel.image, photoset_id: photoset.id, prompt: extra.prompt || '' }
          : {
            kind, model: this.modelSel[kind], prompt: this.prompt.trim(), format: this.format.ratio,
            duration: kind === 'video' ? this.duration : undefined,
            count: kind === 'image' ? this.count : undefined,
            style: this.style !== 'любой' ? this.style : undefined,
            negative: this.negative.trim() || undefined,
          }
        const file = extra.file || (kind === 'image' ? this.refFile : null)
        const r = await api.createGeneration(body, file)
        this.refFile = null
        this.beginGeneration(r, kind, { photoset: !!photoset })
      } catch (e) {
        this.handleActionError(e)
      } finally {
        this.busy = false
      }
    },
    beginGeneration(r, kind, extra = {}) {
      this.setUser(r.user)
      tg.haptic('medium')
      this.gen = {
        ids: r.data.map((g) => g.id), items: r.data, done: false, failed: false, filtered: false,
        cost: r.data.reduce((a, g) => a + g.cost, 0), startedAt: Date.now(), kind, ar: this.format.ar, photoset: false, ...extra,
      }
      this.stack = this.current === 'create' ? this.stack : [...this.stack, 'create']
      this.transition = 'in-push'
      this.plasma.target = 1.25; this.plasma.speed = 2.2
      this.pollGeneration()
    },
    openResult(g) {
      const final = ['done', 'failed', 'cancelled'].includes(g.status)
      this.gen = {
        ids: [g.id], items: [g], done: final, failed: g.status !== 'done' && final, filtered: false,
        cost: g.cost, startedAt: Date.now(), kind: g.kind, ar: 1, photoset: !!g.photoset_id,
      }
      this.push('create')
      if (!final) this.pollGeneration()
    },
    async startTool(tool, file) {
      const info = this.tools.find((t) => t.id === tool)
      if (!info || this.busy) return
      if (this.balance < info.cost) return this.openSheet('need', { lack: info.cost - this.balance })
      this.busy = true
      try {
        this.beginGeneration(await api.createGeneration({ kind: 'tool', tool }, file), 'tool')
      } catch (e) {
        this.handleActionError(e)
      } finally {
        this.busy = false
      }
    },
    pollGeneration() {
      this.stopPolling()
      const tick = async () => {
        const gen = this.gen
        if (!gen || gen.done) return
        try {
          const rs = await Promise.all(gen.ids.map((id) => api.generation(id)))
          gen.items = rs.map((r) => r.data)
          this.setUser(rs[rs.length - 1].user)
          if (gen.items.every((g) => ['done', 'failed', 'cancelled'].includes(g.status))) return this.finishGeneration()
        } catch (e) {
          if (e instanceof ApiError && e.status === 401) return
        }
        this._poll = setTimeout(tick, 1800)
      }
      this._poll = setTimeout(tick, 1200)
    },
    stopPolling() {
      clearTimeout(this._poll)
      this.plasma.target = 0.35; this.plasma.speed = 1
    },
    finishGeneration() {
      const gen = this.gen
      this.stopPolling()
      const ok = gen.items.filter((g) => g.status === 'done')
      gen.done = true
      gen.failed = ok.length === 0
      gen.filtered = gen.items.some((g) => g.error === 'content_filtered')
      if (gen.failed) {
        tg.haptic('error')
        if (gen.filtered) { this.back(); this.openSheet('filter') } else this.toast('Не получилось, токены вернулись', 'cry')
      } else {
        tg.haptic('success')
        this.toast('Готово, отправили в чат', 'party')
        this.refreshRecent()
      }
    },
    async refreshRecent() {
      try { this.recent = (await api.generations()).data.slice(0, 10) } catch { /* ignore */ }
    },
    async cancelGeneration() {
      const gen = this.gen
      if (!gen || gen.done) return
      let cancelled = 0
      for (const id of gen.ids) {
        try { const r = await api.cancelGeneration(id); if (r.cancelled) cancelled++; this.setUser(r.user) } catch { /* ignore */ }
      }
      if (cancelled === gen.ids.length) {
        this.stopPolling()
        this.gen = null
        this.stack = ['home']; this.tab = 'home'
        tg.haptic('warning')
        this.toast('Отменено, токены вернулись')
      } else {
        this.toast('Уже в работе, результат придёт в чат')
      }
    },
    minimize() {
      this.stack = ['home']; this.tab = 'home'
      this.toast('Пришлём результат в чат')
      if (tg.isTelegram) setTimeout(() => tg.close(), 400)
    },
    async toggleFavorite(g) {
      const next = !g.is_favorite
      g.is_favorite = next
      try { await api.updateGeneration(g.id, { is_favorite: next }) } catch { g.is_favorite = !next }
      this.toast(next ? 'В избранном' : 'Убрано из избранного', next ? 'heart' : null)
    },
    async rate(g, value) {
      g.rating = value
      try { await api.updateGeneration(g.id, { rating: value }) } catch { /* ignore */ }
      this.toast(value === 1 ? 'Спасибо за оценку' : 'Сделаем один бесплатный повтор', value === 1 ? 'starstruck' : null)
    },

    handleActionError(e) {
      if (e instanceof ApiError) {
        if (e.code === 'insufficient_balance') { tg.haptic('warning'); return this.openSheet('need', { lack: e.body.required - e.body.balance }) }
        if (e.code === 'model_locked') { this.tier = e.body.plan === 'plus' ? 'plus' : 'pro'; this.push('plans'); return this.toast('Нужна подписка') }
        if (e.status === 422) return this.toast(Object.values(e.body.errors || {}).flat()[0] || 'Проверьте запрос')
        if (e.code === 'network') return this.toast('Нет соединения', 'cry')
        if (e.status === 429) return this.toast('Слишком часто, подождите немного')
      }
      this.toast('Что-то пошло не так, попробуйте ещё раз', 'cry')
    },

    // ---------- chat ----------
    openChat() { this.chat = { id: null, messages: [], busy: false }; this.push('chat') },
    async openChatById(id) {
      try {
        const c = await api.chat(id)
        this.chat = { id: c.id, messages: c.messages.map((m) => ({ ...m })), busy: false }
        this.push('chat')
      } catch (e) { this.handleActionError(e) }
    },
    async sendMessage(text) {
      const m = this.textModel
      if (this.chat.busy) return
      if (this.balance < m.price) return this.openSheet('need', { lack: m.price - this.balance })
      this.chat.messages.push({ id: 'u' + Date.now(), role: 'user', content: text })
      this.chat.messages.push({ id: 'b' + Date.now(), role: 'assistant', content: '', thinking: true, cost: m.price })
      // take the reactive proxies back out of the array, otherwise later mutations are invisible to the UI
      const bot = this.chat.messages[this.chat.messages.length - 1]
      const mine = this.chat.messages[this.chat.messages.length - 2]
      this.chat.busy = true
      tg.haptic('light')
      try {
        const r = await api.sendMessage({ chat_id: this.chat.id, message: text, model: this.modelSel.text })
        this.chat.id = r.chat_id
        this.setUser(r.user)
        await this.streamInto(bot, r.message.content)
        bot.cost = r.message.cost
      } catch (e) {
        this.chat.messages = this.chat.messages.filter((x) => x.id !== bot.id && x.id !== mine.id)
        this.handleActionError(e)
      } finally {
        this.chat.busy = false
      }
    },
    streamInto(msg, full) {
      const words = full.split(' ')
      const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches
      return new Promise((resolve) => {
        let i = 0
        msg.thinking = false
        const tick = () => {
          i = reduce ? words.length : i + 2
          msg.content = words.slice(0, i).join(' ')
          msg.streaming = i < words.length
          if (i < words.length) setTimeout(tick, 40)
          else resolve()
        }
        tick()
      })
    },

    // ---------- history ----------
    async loadHistory() {
      try {
        const r = await api.transactions()
        this.history = { items: r.data, loaded: true }
      } catch { this.history = { items: [], loaded: true, error: true } }
    },

    // ---------- billing ----------
    async pay(kind) {
      if (this.busy) return
      this.busy = true
      try {
        const r = kind === 'topup'
          ? await api.topup(this.pack.tokens)
          : await api.buyPlan(this.tier, this.period)
        this.payment = { id: r.data.id, purpose: r.data.purpose, tokens: r.data.tokens, plan: r.data.plan, startedAt: Date.now() }
        tg.openLink(r.data.confirmation_url)
        this.toast('Откроется страница оплаты')
        this.watchPayment()
      } catch (e) {
        this.handleActionError(e)
      } finally {
        this.busy = false
      }
    },
    /** Polls the pending payment; the webhook usually lands first and the poll just confirms it. */
    watchPayment() {
      clearTimeout(this._payPoll)
      const p = this.payment
      if (!p) return
      const tick = async () => {
        if (!this.payment || this.payment.id !== p.id) return
        try {
          const r = await api.payment(p.id)
          if (r.data.status === 'succeeded') return this.paymentSucceeded(r)
          if (r.data.status === 'canceled') { this.payment = null; return this.toast('Платёж отменён', 'cry') }
        } catch { /* keep trying */ }
        if (Date.now() - p.startedAt < 15 * 60_000) this._payPoll = setTimeout(tick, 4000)
      }
      this._payPoll = setTimeout(tick, 2500)
    },
    paymentSucceeded(r) {
      const p = this.payment
      this.payment = null
      this.setUser(r.user)
      tg.haptic('success')
      confetti()
      this.toast(p.purpose === 'plan' ? `Подписка ${this.planName} активна` : `Начислено ${tok(p.tokens)}`, 'party')
      if (this.current === 'topup') setTimeout(() => this.back(), 900)
    },
    resumePayment() { if (this.payment) this.watchPayment() },

    // ---------- misc ----------
    inviteLink() {
      const id = this.user?.telegram_id
      return this.botUsername ? `https://t.me/${this.botUsername}?start=ref_${id}` : ''
    },
    invite() {
      const link = this.inviteLink()
      if (!link) return this.toast('Ссылка появится после запуска бота')
      tg.share(link, 'Заходи в NeuroGPT: картинки, видео и чат с нейросетями. Нам обоим подарят токены.')
    },
    async copyInvite() {
      const link = this.inviteLink()
      if (!link) return this.toast('Ссылка появится после запуска бота')
      await tg.copy(link)
      this.toast('Ссылка скопирована')
    },
  },
})

export { FORMATS, plural }
