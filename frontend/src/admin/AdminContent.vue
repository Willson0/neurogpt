<template>
  <section class="adm-sec">
    <div class="filters" v-drag-scroll>
      <button v-for="k in kinds" :key="k.id" type="button" class="chip" :aria-pressed="String(kind === k.id)" @click="kind = k.id; reload()">{{ k.name }}</button>
    </div>
    <div class="filters" v-drag-scroll>
      <button v-for="p in periods" :key="p.id" type="button" class="chip" :aria-pressed="String(period === p.id)" @click="period = p.id; p.id !== 'custom' && reload()">{{ p.name }}</button>
    </div>
    <div class="adm-bar">
      <template v-if="period === 'custom'" data-admin-search-context="custom">
        <label class="adm-f">С<input v-model="from" type="date" @change="reload"></label>
        <label class="adm-f">По<input v-model="to" type="date" @change="reload"></label>
      </template>
      <label class="adm-f grow" v-if="kind === 'chat'" data-admin-search-context="chat">Текст сообщения<input v-model.trim="q" type="search" placeholder="Поиск" @keyup.enter="reload" @search="reload"></label>
      <label class="adm-f grow" v-else>Промпт содержит<input v-model.trim="q" type="search" placeholder="Поиск" @keyup.enter="reload" @search="reload"></label>
      <label class="adm-f grow">Пользователь<input v-model.trim="user" type="search" placeholder="@username или Telegram id" @keyup.enter="reload" @search="reload"></label>
      <label v-if="kind !== 'chat'" class="adm-f">Статус
        <select v-model="status" @change="reload"><option value="">Любой</option><option v-for="(n, k) in statuses" :key="k" :value="k">{{ n }}</option></select>
      </label>
    </div>
    <p class="adm-note" aria-live="polite">
      <template v-if="kind === 'chat'">Сообщений: {{ num(summary.total) }}</template>
      <template v-else>Найдено: {{ num(summary.total) }}, готовых: {{ num(summary.done) }}, потрачено токенов: {{ num(summary.tokens) }}</template>
    </p>

    <div v-if="loading && !items.length" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed && !items.length" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="reload">Повторить</button></div>
    <div v-else-if="!items.length" class="empty pad"><Sticker name="sleep" :size="80" /><h3>Ничего не найдено</h3><p>Измените период или фильтры.</p></div>

    <div v-else-if="kind === 'chat'" style="display:grid;gap:var(--space-xs)">
      <div v-for="m in items" :key="m.id" :class="['adm-msg', m.role]">
        <small>{{ m.role === 'user' ? 'Вопрос' : 'Ответ' }} · {{ who(m.user) }} · {{ when(m.created_at) }}<template v-if="m.cost"> · {{ m.cost }} ток.</template></small>
        <p>{{ m.content }}</p>
      </div>
    </div>
    <div v-else class="adm-media">
      <button v-for="g in items" :key="g.id" type="button" class="adm-tile glass" @click="show(g)">
        <span class="ph">
          <video v-if="g.result_url && isVideo(g.result_url)" :src="g.result_url + '#t=0.1'" preload="metadata" muted playsinline />
          <svg v-else-if="g.result_url && isAudio(g.result_url)" class="ic" aria-hidden="true"><use href="#i-music" /></svg>
          <img v-else-if="g.result_url" :src="g.result_url" alt="" loading="lazy">
          <span v-else>{{ statuses[g.status] }}</span>
          <span class="adm-pill">{{ g.title }}</span>
          <span v-if="g.status !== 'done'" class="adm-pill r">{{ statuses[g.status] }}</span>
        </span>
        <span class="cap"><b>{{ g.prompt || 'Без текста' }}</b><span>{{ who(g.user) }} · {{ when(g.created_at) }}</span></span>
      </button>
    </div>
    <button v-if="next" type="button" class="adm-btn soft" :disabled="loading" @click="more">{{ loading ? 'Загружаем…' : 'Показать ещё' }}</button>

    <Teleport to="body">
    <div v-if="open" class="adm-modal" role="dialog" aria-modal="true" aria-labelledby="adm-work-title" @click.self="open = null">
      <div ref="workDialog" class="box adm-work" @keydown.tab="trapFocus">
        <header class="adm-work-head">
          <div><h3 id="adm-work-title">{{ open.music?.title || open.title }}</h3><p class="adm-note">{{ open.music?.title ? open.title + ' · ' : '' }}№{{ open.id }} · {{ statuses[open.status] }}</p></div>
          <button ref="closeWork" type="button" class="adm-btn soft sm" @click="open = null">Закрыть</button>
        </header>
        <section v-if="audioTracks.length" class="adm-work-audio" aria-label="Аудиодорожки">
          <div v-for="(track, index) in audioTracks" :key="index" class="adm-work-track">
            <b>{{ track.title || 'Вариант ' + (index + 1) }}</b>
            <audio :src="track.url" :aria-label="track.title || 'Вариант ' + (index + 1)" controls preload="metadata" @play="pauseOtherTracks" />
          </div>
        </section>
        <div v-else-if="open.result_url" class="adm-work-media">
          <video v-if="isVideo(open.result_url)" :src="open.result_url" controls playsinline />
          <img v-else :src="open.result_url" alt="">
        </div>
        <section v-if="open.status === 'failed'" class="adm-work-error">
          <h4>Журнал ошибок</h4>
          <p v-if="!open.logs?.some(log => log.reason === open.error)">{{ reasons[open.error] || open.error || 'Причина не определена' }}</p>
          <p v-if="logsLoading" class="adm-note" role="status">Загружаем события…</p>
          <button v-else-if="logsFailed" type="button" class="adm-btn soft sm" @click="loadWork(open.id)">Повторить загрузку журнала</button>
          <ul v-else-if="open.logs?.length" class="adm-work-logs">
            <li v-for="(log, index) in open.logs" :key="index">
              <b>{{ reasons[log.reason] || log.reason || 'Ошибка провайдера' }}</b>
              <span class="adm-note">{{ [log.provider, log.operation, log.http_status && 'HTTP ' + log.http_status, log.provider_code && 'API ' + log.provider_code, when(log.created_at)].filter(Boolean).join(' · ') }}</span>
              <small v-if="log.request_id || log.task_id">{{ [log.request_id && 'Запрос ' + log.request_id, log.task_id && 'Задача ' + log.task_id].filter(Boolean).join(' · ') }}</small>
            </li>
          </ul>
          <p v-else class="adm-note">События провайдера для этой работы отсутствуют.</p>
        </section>
        <span class="ctl-label">Промпт пользователя</span>
        <div class="adm-prompt">{{ open.prompt || 'Без текста' }}</div>
        <dl class="adm-kv">
          <dt>Пользователь</dt><dd>{{ open.user ? `${open.user.name}${open.user.username ? ' (@' + open.user.username + ')' : ''}, Telegram id ${open.user.telegram_id}` : 'удалён' }}</dd>
          <dt>Создано</dt><dd>{{ when(open.created_at) }}<template v-if="open.seconds !== null">, заняло {{ open.seconds }} с</template></dd>
          <dt>Статус</dt><dd>{{ statuses[open.status] }}</dd>
          <dt>Модель</dt><dd>{{ open.model ? `${open.model.name} (${open.model.provider_model || open.model.slug})` : 'удалена' }}</dd>
          <template v-if="open.photoset"><dt>Фотосет</dt><dd>{{ open.photoset }}</dd></template>
          <dt>Стоимость</dt><dd>{{ open.cost }} токенов</dd>
          <template v-for="(v, k) in otherParams" :key="k"><dt>{{ params[k] || k }}</dt><dd>{{ v }}</dd></template>
          <template v-if="open.rating"><dt>Оценка</dt><dd>{{ open.rating > 0 ? 'Нравится' : 'Не нравится' }}</dd></template>
          <template v-if="open.result_text"><dt>Текст</dt><dd>{{ open.result_text }}</dd></template>
        </dl>
        <details v-for="(lyric, index) in open.music?.lyrics" :key="index" class="adm-work-details">
          <summary>{{ lyric.title || 'Текст песни' }}</summary>
          <p class="adm-prompt">{{ lyric.text }}</p>
        </details>
        <details v-if="open.params?.music" class="adm-work-details">
          <summary>Параметры музыкальной задачи</summary>
          <pre>{{ JSON.stringify(open.params.music.input ?? open.params.music, null, 2) }}</pre>
        </details>
        <div class="adm-actions">
          <button type="button" class="adm-btn soft sm" @click="copy(open.prompt)">Скопировать промпт</button>
          <a v-if="open.result_url" class="adm-btn soft sm" style="display:inline-flex;align-items:center;text-decoration:none" :href="open.result_url" target="_blank" rel="noopener">Открыть файл</a>
          <button v-if="open.user" type="button" class="adm-btn soft sm" @click="user = String(open.user.telegram_id); open = null; reload()">Все работы автора</button>
          <button v-if="open.has_input" type="button" class="adm-btn soft sm" @click="showInput(open)">Исходное фото</button>
          <button type="button" class="adm-btn danger sm" :disabled="busy" @click="remove(open)">{{ confirmId === open.id ? 'Точно удалить?' : 'Удалить' }}</button>
        </div>
      </div>
    </div>
    </Teleport>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { isVideoUrl, isAudioUrl } from '../services/format'
import Sticker from '../components/Sticker.vue'
import { section, PERIODS, periodRange, STATUSES } from './shared'

export default {
  name: 'AdminContent',
  components: { Sticker },
  mixins: [section],
  props: { initialUser: { type: String, default: '' }, searchContext: { type: String, default: '' } },
  data() {
    return {
      kinds: [
        { id: '', name: 'Все' }, { id: 'image', name: 'Картинки' }, { id: 'photoset', name: 'Фотосеты' }, { id: 'video', name: 'Видео' },
        { id: 'tool', name: 'Инструменты' }, { id: 'song', name: 'Песни' }, { id: 'chat', name: 'Чат' },
      ],
      periods: PERIODS, statuses: STATUSES,
      reasons: {
        copyright: 'Ограничение по авторским правам', moderation: 'Запрос отклонён модерацией',
        content_filtered: 'Запрос не прошёл фильтр', credits: 'Недостаточно средств у провайдера',
        authorization: 'Ошибка авторизации', rate_limit: 'Превышен лимит запросов',
        size: 'Превышен размер файла', not_found: 'Задача или файл не найдены',
        timeout: 'Истекло время ожидания', connection: 'Ошибка соединения',
        input: 'Запрос отклонён', result: 'Некорректный результат', upstream: 'Сбой провайдера',
        failed: 'Ошибка генерации', unavailable: 'Модель недоступна', unknown: 'Причина не определена',
      },
      logsLoading: false, logsFailed: false, workSeq: 0,
      params: { format: 'Формат', style: 'Стиль', negative: 'Не должно быть', duration: 'Длительность, с', option: 'Вариант' },
      kind: this.searchContext === 'chat' ? 'chat' : '', period: this.searchContext === 'custom' ? 'custom' : 'all', from: '', to: '', q: '', user: this.initialUser, status: '',
      items: [], next: null, summary: { total: 0, done: 0, tokens: 0 }, open: null, confirmId: null, seq: 0,
    }
  },
  computed: {
    audioTracks() {
      if (this.open?.music?.tracks?.length) return this.open.music.tracks
      return this.open?.result_url && this.isAudio(this.open.result_url) ? [{ url: this.open.result_url }] : []
    },
    otherParams() {
      const { music, ...params } = this.open?.params || {}
      return params
    },
  },
  mounted() {
    this.reload()
    const generation = new URLSearchParams(location.search).get('generation')
    if (/^[1-9]\d*$/.test(generation || '')) this.loadWork(generation, true)
    this.onKey = (e) => { if (e.key === 'Escape' && this.open) { e.stopImmediatePropagation(); this.open = null } }
    document.addEventListener('keydown', this.onKey, true)
  },
  beforeUnmount() { document.removeEventListener('keydown', this.onKey, true) },
  watch: {
    open(value, previous) {
      this.confirmId = null
      if (!value) {
        this.workSeq++
        this.returnFocus?.focus()
      } else if (!previous) this.$nextTick(() => this.$refs.closeWork?.focus())
    },
  },
  methods: {
    trapFocus(event) {
      const items = [...this.$refs.workDialog.querySelectorAll('button:not(:disabled), a[href], audio[controls], video[controls], summary')]
        .filter(item => item.getClientRects().length)
      const first = items[0]
      const last = items.at(-1)
      if (!first || !(event.shiftKey ? document.activeElement === first : document.activeElement === last)) return
      event.preventDefault()
      const next = event.shiftKey ? last : first
      next.focus()
    },
    show(work) {
      ++this.workSeq
      this.returnFocus = document.activeElement
      this.open = work
      this.logsFailed = false
      this.logsLoading = false
      if (work.status === 'failed') this.loadWork(work.id)
    },
    async loadWork(id, direct = false) {
      const seq = ++this.workSeq
      this.logsLoading = true
      this.logsFailed = false
      try {
        const result = await adminApi.list('generations/' + id)
        if (seq === this.workSeq) this.open = result.data
      } catch {
        if (seq !== this.workSeq) return
        this.logsFailed = true
        if (direct) this.app.toast('Не удалось открыть работу')
      } finally { if (seq === this.workSeq) this.logsLoading = false }
    },
    pauseOtherTracks(event) {
      event.target.closest('.adm-work-audio')?.querySelectorAll('audio').forEach(audio => {
        if (audio !== event.target) audio.pause()
      })
    },
    isVideo: isVideoUrl, isAudio: isAudioUrl,
    query(page) {
      const base = { ...periodRange(this.period, this.from, this.to), q: this.q, user: this.user.replace(/^@/, ''), page }
      return this.kind === 'chat' ? base : { ...base, kind: this.kind, status: this.status }
    },
    reload() { this.items = []; this.next = null; return this.load(1) },
    more() { return this.load(this.next) },
    load(page) {
      const seq = ++this.seq
      return this.fetch(async () => {
        const r = await adminApi.list(this.kind === 'chat' ? 'messages' : 'generations', this.query(page))
        if (seq !== this.seq) return // a newer filter is already loading
        this.items = page === 1 ? r.data : [...this.items, ...r.data]
        this.next = r.next_page
        this.summary = { total: 0, done: 0, tokens: 0, ...r.summary }
      })
    },
    async copy(text) {
      try { await navigator.clipboard.writeText(text || ''); this.app.toast('Скопировано') } catch { this.app.toast('Не получилось скопировать') }
    },
    showInput(g) { return this.run(async () => { window.open(await adminApi.input(g.id), '_blank') }) },
    async remove(g) {
      if (this.confirmId !== g.id) { this.confirmId = g.id; return }
      const ok = await this.run(() => adminApi.remove('generations', g.id), 'Удалено')
      if (ok) { this.items = this.items.filter((x) => x.id !== g.id); this.summary.total--; this.open = null }
    },
  },
}
</script>
