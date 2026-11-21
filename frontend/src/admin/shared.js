import { ApiError } from '../services/api'
import { useApp } from '../stores/app'

/** The first readable message of a failed admin request. */
export function errorText(e) {
  if (e instanceof ApiError) {
    const first = Object.values(e.body?.errors || {})[0]
    if (first) return Array.isArray(first) ? first[0] : String(first)
    if (e.status === 403) return 'Нет доступа'
    if (e.code === 'network') return 'Нет соединения'
    return e.body?.message || 'Не получилось сохранить'
  }
  return 'Что-то пошло не так'
}

export const PERIODS = [
  { id: 'all', name: 'Всё время' },
  { id: 'today', name: 'Сегодня' },
  { id: '7', name: '7 дней' },
  { id: '30', name: '30 дней' },
  { id: 'custom', name: 'Период' },
]

/** {from, to} as ISO strings in the admin's own time zone. */
export function periodRange(id, from, to) {
  const start = (d) => { d.setHours(0, 0, 0, 0); return d }
  if (id === 'today') return { from: start(new Date()).toISOString() }
  if (id === '7' || id === '30') { const d = new Date(); d.setDate(d.getDate() - (Number(id) - 1)); return { from: start(d).toISOString() } }
  if (id === 'custom') {
    const out = {}
    if (from) out.from = start(new Date(from + 'T00:00')).toISOString()
    if (to) { const d = new Date(to + 'T00:00'); d.setHours(23, 59, 59, 999); out.to = d.toISOString() }
    return out
  }
  return {}
}

export const when = (iso) => iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''
export const num = (n) => Number(n || 0).toLocaleString('ru-RU')
export const who = (u) => u ? (u.username ? '@' + u.username : u.name || 'id ' + u.telegram_id) : 'удалён'

/** Loading and saving with one error path: `await this.run(() => api.call(), 'Сохранено')`. */
export const section = {
  data: () => ({ busy: false, loading: true, failed: false }),
  computed: { app() { return useApp() } },
  methods: {
    num, when, who,
    async run(fn, ok) {
      if (this.busy) return false
      this.busy = true
      try {
        await fn()
        if (ok) this.app.toast(ok)
        return true
      } catch (e) {
        this.app.toast(errorText(e), 'cry')
        return false
      } finally {
        this.busy = false
      }
    },
    async fetch(fn) {
      this.loading = true
      this.failed = false
      try { await fn() } catch { this.failed = true } finally { this.loading = false }
    },
  },
}

export const KINDS = { image: 'Картинки', video: 'Видео', tool: 'Инструменты', song: 'Песни', photoset: 'Фотосеты' }
export const STATUSES = { queued: 'В очереди', processing: 'В работе', done: 'Готово', failed: 'Ошибка', cancelled: 'Отменено' }
export const DRIVERS = { openai: 'OpenAI-совместимый API', kie: 'kie.ai', replicate: 'Replicate', fake: 'Заглушка (без сети)', unavailable: 'Недоступна' }
/** Model options as editable rows and back. */
export const toPairs = (obj, skip = []) => Object.entries(obj || {}).filter(([k]) => !skip.includes(k)).map(([key, value]) => ({ key, value: String(value) }))
export const fromPairs = (rows) => Object.fromEntries(rows.filter((r) => r.key.trim() && String(r.value).trim() !== '').map((r) => [r.key.trim(), /^-?\d+(\.\d+)?$/.test(r.value) ? Number(r.value) : r.value]))
