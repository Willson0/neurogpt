import { defineStore } from 'pinia'
import { api, ApiError } from '../services/api'
import { tg } from '../services/telegram'
import { tok, plural, checkImage } from '../services/format'
import { bus, confetti } from '../services/bus'
import { theme } from '../services/theme'
import { startTracking } from '../services/track'
import { applyLink } from '../services/deeplink'

const ROOT = ['home', 'gallery', 'profile']
const FORMATS = [
  { ratio: '1:1', ar: 1, w: 14, h: 14 },
  { ratio: '9:16', ar: 0.5625, w: 10, h: 17 },
  { ratio: '16:9', ar: 1.7778, w: 18, h: 10 },
]
const PLAN_RANK = { free: 0, plus: 1, pro: 2 }
/** The direct link to the admin panel: https://<app>/admin (a page of its own, admin.html). */
export const adminLink = () => /^\/admin(\.html)?$/.test(location.pathname.replace(/\/+$/, ''))
/** Leaves the Mini App for the admin page, optionally on a section. Telegram keeps initData across the navigation. */
export const openAdmin = (section = '') => { location.href = '/admin' + (section ? '?section=' + encodeURIComponent(section) : '') }

export const useApp = defineStore('app', {
  state: () => ({
    booting: true,
    bootError: null,
    galleryError: false,
    user: null,
    models: { image: [], video: [], song: [] },
    photosets: [],
    news: [],
    packs: [],
    plans: {},
    tools: [],
    styles: [],
    features: { songs: false },
    payments: { card: false, stars: false },
    limits: { upload_mb: 10 },
    supportUrl: null,
    bonus: {},
    recent: [],
    botUsername: '',

    stack: ['home'],
    standalone: false, // true on the admin page: no catalog to keep fresh there
    tab: 'home',
    sheet: null, // { name, props }
    toastState: null, // { id, text, sticker }

    // composer
    mode: 'image',
    modelSel: { image: 'auto', video: null, song: 'auto' },
    fmtIndex: 0,
    duration: 5,
    setKind: 'photo', // gallery tab: photo | video
    songDuration: 30,
    count: 1,
    style: 'any',
    negative: '',
    prompt: '',
    refFile: null,
    improving: false,

    // generation screen
    gen: null, // { items: [...], done, failed, pendingCost, startedAt, photoset }
    history: { items: [], loaded: false },
    // billing
    packIndex: 1,
    tier: 'pro',
    period: 'month',
    payMethod: 'card',
    payment: null, // pending payment being watched
    busy: false,

    plasma: { target: 0.35, speed: 1 },
  }),

  getters: {
    current: (s) => s.stack[s.stack.length - 1],
    canGoBack: (s) => s.stack.length > 1,
    balance: (s) => s.user?.balance ?? 0,
    plan: (s) => s.user?.plan ?? 'free',
    isAdmin: (s) => !!s.user?.is_admin,
    planName() { return this.plans[this.plan]?.name || 'Free' },
    /** Formats the current model supports: every one for pictures, the model's own list for video. */
    formats() {
      const allowed = this.mode === 'video' ? this.model.formats : null
      const list = allowed?.length ? FORMATS.filter((f) => allowed.includes(f.ratio)) : FORMATS
      return list.length ? list : FORMATS
    },
    format() { return this.formats[this.fmtIndex % this.formats.length] },
    /** Video lengths of the current model with their prices. */
    durations() { return this.mode === 'video' ? (this.model.durations || []) : [] },
    durationOpt() { return this.durations.find((d) => d.seconds === this.duration) || this.durations[0] || null },
    styleName() { return (this.styles.find((x) => x.id === this.style)?.name || 'любой').toLowerCase() },
    songsOff: (s) => !s.features.songs,
    payMethods() { return ['card', 'stars'].filter((m) => this.payments[m]) },
    method() { return this.payMethods.includes(this.payMethod) ? this.payMethod : this.payMethods[0] || null },
    /** Price of a pack or plan in the chosen payment method, ready to show. */
    priceLabel() { return (rub, stars) => (this.method === 'stars' ? `${Number(stars || 0).toLocaleString('ru-RU')} ⭐` : `${Number(rub || 0).toLocaleString('ru-RU')} ₽`) },
    modelList: (s) => s.models[s.mode === 'tools' ? 'image' : s.mode] || [],
    model() {
      const list = this.models[this.mode === 'tools' ? 'image' : this.mode] || []
      return list.find((m) => m.id === this.modelSel[this.mode]) || list[0] || { id: 'auto', name: 'Авто', hint: '', price: 0 }
    },
    cost() {
      const m = this.model
      if (this.mode === 'video') return this.durationOpt?.price ?? m.price
      if (this.mode === 'song') return m.price * (this.songDuration / 30)
      if (this.mode === 'image') return m.price * this.count
      return m.price
    },
    pack: (s) => s.packs[s.packIndex] || { tokens: 0, price: 0 },
    isLocked: (s) => (m) => (PLAN_RANK[s.user?.plan] ?? 0) < (PLAN_RANK[m.min_plan] ?? 0),
    trends: (s) => s.photosets.filter((p) => p.trending),
    /** Sets shown on the gallery tab: photo sets or video sets. */
    setsShown: (s) => s.photosets.filter((p) => (p.kind || 'photo') === s.setKind),
    hasVideoSets: (s) => s.photosets.some((p) => p.kind === 'video'),
    /** The video model a video set runs on: the one chosen on the video tab, else the first. */
    setVideoModel: (s) => (s.models.video || []).find((m) => m.id === s.modelSel.video) || (s.models.video || [])[0] || null,
    /** What one run of a set costs: the image model price, or the shortest video of the video model. */
    setPrice() {
      return (ps) => {
        if (ps?.kind === 'video') return this.setVideoModel?.durations?.[0]?.price ?? this.setVideoModel?.price ?? 0
        return (this.models.image.find((m) => m.id === this.modelSel.image) || this.models.image[0] || {}).price || 0
      }
    },
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
        // a bot button or a t.me link may ask for a given screen (?startapp=...): opened once, on the first start
        if (!this._linked) { this._linked = true; await applyLink(this) }
        startTracking(this)
        this.refreshDailyBonus()
      } catch (e) {
        this.bootError = e instanceof ApiError ? e : new ApiError(0, { code: 'network' })
      } finally {
        this.booting = false
      }
    },
    applyBootstrap(d) {
      this.user = d.user
      this.models = { image: [], video: [], song: [], ...d.models }
      this.photosets = d.photosets
      this.news = d.news
      this.packs = d.packs
      this.plans = d.plans
      this.tools = d.tools || []
      this.styles = d.styles || []
      this.features = { songs: false, ...d.features }
      this.payments = { card: false, stars: false, ...d.payments }
      this.limits = { upload_mb: 10, ...d.limits }
      this.supportUrl = d.support_url || null
      this.bonus = d.bonus
      theme.apply(d.theme)
      this.recent = d.recent
      this.botUsername = d.bot_username || ''
      if (this.packIndex >= this.packs.length) this.packIndex = Math.min(1, this.packs.length - 1)
      // the recommended video model that the plan allows is the default one
      if (!this.models.video.some((m) => m.id === this.modelSel.video)) {
        const open = this.models.video.filter((m) => !this.isLocked(m))
        this.modelSel.video = (open.find((m) => m.badge === 'Советуем') || open[0] || this.models.video[0])?.id || null
      }
    },
    /** After a change in the admin panel: the same data as on start, without the boot screen. */
    async reloadCatalog() {
      if (this.standalone) return
      try { this.applyBootstrap(await api.bootstrap()) } catch { /* the next start picks it up */ }
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
    cycleFormat() { this.fmtIndex = (this.fmtIndex % this.formats.length + 1) % this.formats.length; tg.haptic('selection') },
    toggleDuration() {
      const list = this.durations
      if (list.length < 2) return this.toast(list.length ? `Эта модель снимает ${list[0].seconds} секунд` : 'Длительность задаёт модель')
      this.duration = list[(list.indexOf(this.durationOpt) + 1) % list.length].seconds
      tg.haptic('selection')
    },
    /** A reference picture (or the first frame of a video): checked before it is kept. */
    setRef(file) {
      if (!file) { this.refFile = null; return }
      const err = checkImage(file, this.limits.upload_mb)
      if (err) return this.toast(err, 'cry')
      this.refFile = file
      tg.haptic('light')
    },
    cycleSongDuration() {
      const steps = [30, 60, 120]
      this.songDuration = steps[(steps.indexOf(this.songDuration) + 1) % steps.length]
      tg.haptic('selection')
    },
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
    async improvePrompt() {
      const p = this.prompt.trim()
      if (p.length < 2) return this.toast('Сначала пара слов')
      if (this.improving) return
      this.improving = true
      try {
        const r = await api.improvePrompt(p, this.mode === 'video' ? 'video' : 'image')
        if (r.prompt) { this.prompt = r.prompt; this.kick(1); tg.haptic('light'); this.toast('Запрос улучшен бесплатно', 'magic') }
      } catch (e) {
        if (e instanceof ApiError && e.code === 'improve_limit') this.toast('На сегодня улучшения закончились')
        else this.handleActionError(e)
      } finally {
        this.improving = false
      }
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
      const videoSet = photoset?.kind === 'video' ? this.setVideoModel : null
      if (photoset?.kind === 'video' && !videoSet) return this.toast('Видео сейчас недоступно')
      const kind = videoSet ? 'video' : this.mode === 'video' ? 'video' : this.mode === 'song' ? 'song' : 'image'
      if (kind === 'song' && this.songsOff) return this.toast('Песни скоро появятся', 'hourglass')
      const locked = videoSet || (!photoset && this.model)
      if (locked && this.isLocked(locked)) { this.tier = locked.min_plan === 'plus' ? 'plus' : 'pro'; this.toast('Модель доступна по подписке'); return this.push('plans') }
      const cost = photoset ? this.setPrice(photoset) : this.cost
      const setFormat = videoSet ? ((videoSet.formats || []).includes('9:16') ? '9:16' : (videoSet.formats || [])[0]) : null
      if (this.balance < cost) { tg.haptic('warning'); return this.openSheet('need', { lack: cost - this.balance }) }
      if (this.busy) return
      this.busy = true
      try {
        const body = videoSet
          ? { kind: 'video', model: videoSet.id, photoset_id: photoset.id, prompt: extra.prompt || '', duration: videoSet.durations?.[0]?.seconds, format: setFormat }
          : photoset
          ? { kind: 'image', model: this.modelSel.image, photoset_id: photoset.id, prompt: extra.prompt || '' }
          : {
            kind, model: this.model.id, prompt: this.prompt.trim(), format: kind === 'song' ? undefined : this.format.ratio,
            duration: kind === 'video' ? this.durationOpt?.seconds : kind === 'song' ? this.songDuration : undefined,
            count: kind === 'image' ? this.count : undefined,
            style: kind === 'image' && this.style !== 'any' ? this.style : undefined,
            negative: this.negative.trim() || undefined,
          }
        const file = extra.file || (kind === 'image' || kind === 'video' ? this.refFile : null)
        const r = await api.createGeneration(body, file)
        this.refFile = null
        this.beginGeneration(r, kind, { photoset: !!photoset, ...(videoSet ? { ar: setFormat === '16:9' ? 16 / 9 : setFormat === '1:1' ? 1 : 9 / 16 } : {}) })
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
        cost: r.data.reduce((a, g) => a + g.cost, 0), startedAt: Date.now(), kind, ar: kind === 'song' ? 1.6 : this.format.ar, photoset: false, ...extra,
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
    async startTool(tool, file, sourceId = null, extra = {}) {
      const info = this.tools.find((t) => t.id === tool)
      if (!info || this.busy) return
      if (this.balance < info.cost) return this.openSheet('need', { lack: info.cost - this.balance })
      this.busy = true
      try {
        const body = { kind: 'tool', tool, source_generation_id: file ? null : sourceId, prompt: extra.prompt || undefined, option: extra.option || undefined }
        this.beginGeneration(await api.createGeneration(body, file), 'tool')
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
          if (this.gen !== gen) return
          gen.items = rs.map((r) => r.data)
          this.setUser(rs[rs.length - 1].user)
          if (gen.items.every((g) => ['done', 'failed', 'cancelled'].includes(g.status))) return this.finishGeneration()
        } catch (e) {
          if (e instanceof ApiError && e.status === 401) return
        }
        // video takes minutes: no need to ask every second
        this._poll = setTimeout(tick, gen.kind === 'video' || Date.now() - gen.startedAt > 60_000 ? 4000 : 1800)
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
        if (gen.filtered) { this.back(); this.openSheet('filter') }
        else if (gen.items.some((g) => g.error === 'unavailable')) this.toast('Модель сейчас недоступна, токены вернулись', 'cry')
        else this.toast('Не получилось, токены вернулись', 'cry')
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
      this.toast(value === 1 ? 'Спасибо за оценку' : 'Спасибо, учтём', value === 1 ? 'starstruck' : null)
    },

    handleActionError(e) {
      if (e instanceof ApiError) {
        if (e.code === 'insufficient_balance') { tg.haptic('warning'); return this.openSheet('need', { lack: e.body.required - e.body.balance }) }
        if (e.code === 'model_locked') { this.tier = e.body.plan === 'plus' ? 'plus' : 'pro'; this.push('plans'); return this.toast('Нужна подписка') }
        if (e.code === 'coming_soon') return this.toast('Песни скоро появятся', 'hourglass')
        if (e.code === 'too_many_active') return this.toast('Дождитесь текущих генераций', 'hourglass')
        if (e.code === 'plan_downgrade') return this.toast('У вас уже тариф выше')
        if (e.code === 'payment_error') return this.toast('Не удалось создать платёж', 'cry')
        if (e.status === 413) return this.toast(`Фото больше ${this.limits.upload_mb} МБ`, 'cry')
        if (e.status === 422) return this.toast(Object.values(e.body.errors || {}).flat()[0] || 'Проверьте запрос')
        if (e.code === 'network') return this.toast('Нет соединения', 'cry')
        if (e.status === 429) return this.toast('Слишком часто, подождите немного')
      }
      this.toast('Что-то пошло не так, попробуйте ещё раз', 'cry')
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
      const method = this.method
      if (!method) return this.toast('Оплата временно недоступна', 'cry')
      if (method === 'stars' && !tg.canInvoice) return this.toast('Оплата звёздами работает в Telegram')
      this.busy = true
      try {
        const r = kind === 'topup'
          ? await api.topup(this.pack.tokens, method)
          : await api.buyPlan(this.tier, this.period, method)
        this.payment = { id: r.data.id, purpose: r.data.purpose, tokens: r.data.tokens, plan: r.data.plan, startedAt: Date.now() }
        if (method === 'stars') {
          this.busy = false
          const status = await tg.openInvoice(r.data.confirmation_url)
          if (status === 'paid' || status === 'pending') this.watchPayment(600)
          else { this.payment = null; this.toast(status === 'cancelled' ? 'Оплата отменена' : 'Оплата не прошла', 'cry') }
        } else {
          tg.openLink(r.data.confirmation_url)
          this.toast('Откроется страница оплаты')
          this.watchPayment()
        }
      } catch (e) {
        this.handleActionError(e)
      } finally {
        this.busy = false
      }
    },
    /** Polls the pending payment; the webhook usually lands first and the poll just confirms it. */
    watchPayment(first = 2500) {
      clearTimeout(this._payPoll)
      const p = this.payment
      if (!p) return
      const tick = async () => {
        if (!this.payment || this.payment.id !== p.id) return
        try {
          const r = await api.payment(p.id)
          if (r.data.status === 'succeeded') return this.paymentSucceeded(r)
          if (['canceled', 'failed', 'expired'].includes(r.data.status)) { this.payment = null; return this.toast('Платёж не прошёл', 'cry') }
        } catch { /* keep trying */ }
        if (Date.now() - p.startedAt < 15 * 60_000) this._payPoll = setTimeout(tick, 4000)
        else this.payment = null
      }
      this._payPoll = setTimeout(tick, first)
    },
    paymentSucceeded(r) {
      const p = this.payment
      this.payment = null
      this.setUser(r.user)
      tg.haptic('success')
      confetti()
      this.toast(p.purpose === 'plan' ? `Подписка ${this.planName} активна` : `Начислено ${tok(p.tokens)}`, 'party')
      if (['topup', 'plans'].includes(this.current)) setTimeout(() => ['topup', 'plans'].includes(this.current) && this.back(), 900)
    },
    resumePayment() { if (this.payment) this.watchPayment() },

    // ---------- misc ----------
    inviteLink() {
      // built by the server: https://t.me/<bot>?start=friend_<id>
      return this.user?.invite_link || ''
    },
    invite() {
      const link = this.inviteLink()
      if (!link) return this.toast('Ссылка появится после запуска бота')
      tg.share(link, 'Заходи в NeuroGPT: картинки и видео от нейросетей. Нам обоим подарят токены.')
    },
    async copyInvite() {
      const link = this.inviteLink()
      if (!link) return this.toast('Ссылка появится после запуска бота')
      const ok = await tg.copy(link)
      this.toast(ok ? 'Ссылка скопирована' : 'Не удалось скопировать', ok ? null : 'cry')
    },
  },
})

export { FORMATS, plural }
