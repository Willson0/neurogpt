<template>
  <section class="adm-sec">
    <div class="filters" v-drag-scroll>
      <button v-for="p in periods" :key="p.id" type="button" class="chip" :aria-pressed="String(period === p.id)" @click="period = p.id; p.id !== 'custom' && load()">{{ p.name }}</button>
    </div>
    <div v-if="period === 'custom'" class="adm-bar">
      <label class="adm-f">С<input v-model="from" type="date" @change="load"></label>
      <label class="adm-f">По<input v-model="to" type="date" @change="load"></label>
    </div>
    <div v-if="loading && !s" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed && !s" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else-if="s">
      <div class="adm-stats" :aria-busy="String(loading)">
        <div class="stat glass"><span>Пользователи</span><b>{{ num(s.users.total) }}</b><small>новых: {{ num(s.users.new) }}</small></div>
        <div class="stat glass"><span>Активные</span><b>{{ num(s.users.active) }}</b><small>создавали: {{ num(s.users.creators) }}</small></div>
        <div class="stat glass"><span>С подпиской</span><b>{{ num(s.users.subscribed) }}</b><small>заблокировано: {{ num(s.users.blocked) }}</small></div>
        <div class="stat glass"><span>Генерации</span><b>{{ num(s.generations.total) }}</b><small>готово {{ num(s.generations.done) }}, ошибок {{ num(s.generations.failed) }}</small></div>
        <div class="stat glass"><span>Потрачено токенов</span><b>{{ num(s.tokens.spent) }}</b><small>возвращено: {{ num(s.tokens.refunded) }}</small></div>
        <div class="stat glass"><span>Продано токенов</span><b>{{ num(s.tokens.sold) }}</b><small>бонусами: {{ num(s.tokens.bonus) }}</small></div>
        <div class="stat glass"><span>Выручка</span><b>{{ num(s.payments.rub) }} ₽</b><small>{{ num(s.payments.count) }} оплат<template v-if="s.payments.by_currency.XTR">, {{ num(s.payments.by_currency.XTR) }} ⭐</template></small></div>
        <div class="stat glass"><span>Платящие</span><b>{{ num(s.payments.payers) }}</b><small>средний чек: {{ num(avgCheck) }} ₽</small></div>
        <div class="stat glass"><span>Нажатия кнопок</span><b>{{ num(s.clicks.total) }}</b><small>в приложении</small></div>
        <div class="stat glass"><span>Сообщения в чате</span><b>{{ num(s.chat.messages) }}</b><small>токенов: {{ num(s.chat.tokens) }}</small></div>
        <div class="stat glass"><span>Сейчас в работе</span><b>{{ num(s.generations.active) }}</b><small v-if="s.generations.avg_seconds !== null">в среднем {{ s.generations.avg_seconds }} с</small><small v-else>генераций</small></div>
        <div class="stat glass"><span>Запросы (query_logs)</span><b>{{ num(s.bot.queries_total) }}</b><small>токенов: {{ num(s.bot.tokens) }}</small></div>
      </div>

      <div class="adm-card glass">
        <div class="adm-bar"><h3 class="grow">По дням</h3><span class="adm-note">{{ chartRange }}</span></div>
        <div class="filters" v-drag-scroll>
          <button v-for="m in metrics" :key="m.id" type="button" class="chip" :aria-pressed="String(metric === m.id)" @click="metric = m.id">{{ m.name }}</button>
        </div>
        <p class="adm-note">Всего за график: <b>{{ seriesTotal }}</b>. Наведите на столбик, чтобы увидеть день.</p>
        <AdminChart :series="s.series" :metric="metric" :label="metricName" :money="metric === 'rub'" />
      </div>

      <div class="adm-grid wide">
        <AdminBars title="Нажатия кнопок в приложении" :rows="clickRows" :total="s.clicks.total" :limit="12"
          note="Сколько раз нажали каждую кнопку и сколько разных людей. Считается с этого обновления."
          empty="Нажатий пока нет: они начнут считаться, когда люди откроют обновлённое приложение." />
        <AdminBars title="Экраны и окна" :rows="screenRows" note="Сколько раз открывали экран и сколько людей." empty="Просмотров пока нет." />
        <AdminBars title="Генерации по типам" :rows="kindRows" :total="s.generations.total" />
        <AdminBars title="Модели" :rows="modelRows" note="Генераций и потраченных токенов, без сетов и инструментов." />
        <AdminBars title="Фотосеты и видеосеты" :rows="setRows" />
        <AdminBars title="Инструменты" :rows="toolRows" />
        <AdminBars title="Статусы генераций" :rows="statusRows" />
        <AdminBars title="Оплаты" :rows="payRows" :total="s.payments.count" :note="s.payments.pending ? `Ждут оплаты: ${num(s.payments.pending)}` : ''" />
        <AdminBars title="Бот: нажатия и команды" :rows="botRows" :limit="12" note="Из таблицы логов бота (logs_table), только чтение." empty="Бот не записал действий за этот период." />
        <AdminBars title="Бот: запросы по типам" :rows="botQueryRows" :total="s.bot.queries_total" note="Таблица query_logs: запросы из бота и из приложения." />
      </div>
    </template>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section, PERIODS, periodRange, KINDS, STATUSES, num } from './shared'
import AdminChart from './AdminChart.vue'
import AdminBars from './AdminBars.vue'

const SCREENS = { home: 'Главная', gallery: 'Сеты', profile: 'Профиль', history: 'История', topup: 'Пополнение', plans: 'Подписки', create: 'Генерация' }
const SHEETS = {
  models: 'выбор модели', more: 'ещё', invite: 'пригласить друга', photoset: 'сет', psetUpload: 'сет: фото', need: 'не хватает токенов',
  tool: 'инструмент', first: 'приветствие', filter: 'фильтр', resultMore: 'результат: ещё',
}
/** "home:models" -> "Главная · выбор модели" */
export const screenName = (s) => {
  if (!s) return ''
  const [view, sheet] = s.split(':')
  return (SCREENS[view] || view) + (sheet ? ' · ' + (SHEETS[sheet] || sheet) : '')
}

export default {
  name: 'AdminDashboard',
  components: { AdminChart, AdminBars },
  mixins: [section],
  data: () => ({
    s: null, period: '30', from: '', to: '', periods: PERIODS, seq: 0,
    metric: 'generations',
    metrics: [
      { id: 'generations', name: 'Генерации' }, { id: 'tokens', name: 'Токены' }, { id: 'active', name: 'Активные' },
      { id: 'users', name: 'Новые' }, { id: 'clicks', name: 'Нажатия' }, { id: 'rub', name: 'Выручка' }, { id: 'payments', name: 'Оплаты' }, { id: 'chat', name: 'Чат' },
    ],
  }),
  computed: {
    metricName() { return this.metrics.find((m) => m.id === this.metric)?.name || '' },
    seriesTotal() {
      const t = this.s.series.reduce((a, d) => a + Number(d[this.metric] || 0), 0)
      if (this.metric === 'active') return 'сумма по дням ' + num(t)
      return num(Math.round(t * 100) / 100) + (this.metric === 'rub' ? ' ₽' : '')
    },
    chartRange() {
      const a = this.s.series[0]?.day, b = this.s.series[this.s.series.length - 1]?.day
      const d = (x) => new Date(x + 'T00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
      return a ? `${d(a)} – ${d(b)}` : ''
    },
    avgCheck() { return this.s.payments.count ? Math.round(this.s.payments.rub / this.s.payments.count) : 0 },
    clickRows() { return this.s.clicks.top.map((r) => ({ label: r.name, sub: screenName(r.screen), n: r.n, extra: ` · ${num(r.users)} чел.` })) },
    screenRows() { return this.s.clicks.screens.map((r) => ({ label: screenName(r.name), n: r.n, extra: ` · ${num(r.users)} чел.` })) },
    kindRows() {
      const names = { ...KINDS, set_image: 'Фотосеты', set_video: 'Видеосеты', image: 'Картинки', video: 'Видео' }
      return this.s.generations.by_kind.map((r) => ({ label: names[r.key] || r.key, n: r.n, extra: ` · ${num(r.tokens)} ток.` }))
    },
    modelRows() { return this.s.generations.models.map((r) => ({ label: r.name, sub: KINDS[r.kind] || (r.kind === 'text' ? 'Текст' : r.kind), n: r.n, extra: ` · ${num(r.tokens)} ток.` })) },
    setRows() { return this.s.generations.sets.map((r) => ({ label: r.name, sub: r.kind === 'video' ? 'Видеосет' : 'Фотосет', n: r.n, extra: ` · ${num(r.tokens)} ток.` })) },
    toolRows() { return this.s.generations.tools.map((r) => ({ label: r.name, n: r.n, extra: ` · ${num(r.tokens)} ток.` })) },
    statusRows() { return Object.entries(this.s.generations.by_status).map(([k, n]) => ({ label: STATUSES[k] || k, n })).sort((a, b) => b.n - a.n) },
    payRows() {
      const names = { topup: 'Пополнения', plan: 'Подписки' }
      return Object.entries(this.s.payments.by_purpose).map(([k, n]) => ({ label: names[k] || k, n }))
    },
    botRows() { return this.s.bot.actions.map((r) => ({ label: r.name, n: r.n, extra: ` · ${num(r.users)} чел.` })) },
    botQueryRows() { return this.s.bot.queries.map((r) => ({ label: r.name, n: r.n, extra: ` · ${num(r.tokens)} ток.` })) },
  },
  mounted() { this.load() },
  methods: {
    load() {
      const seq = ++this.seq
      return this.fetch(async () => {
        const r = await adminApi.analytics(periodRange(this.period, this.from, this.to))
        if (seq === this.seq) this.s = r
      })
    },
  },
}
</script>
