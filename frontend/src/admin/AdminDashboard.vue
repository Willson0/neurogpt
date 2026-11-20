<template>
  <section class="adm-sec">
    <div class="filters" v-drag-scroll>
      <button v-for="p in periods" :key="p.id" type="button" class="chip" :aria-pressed="String(period === p.id)" @click="period = p.id; load()">{{ p.name }}</button>
    </div>
    <div v-if="loading && !s" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed && !s" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else-if="s">
      <div class="adm-stats">
        <div class="stat glass"><span>Пользователи</span><b>{{ num(s.users.total) }}</b><small>новых: {{ num(s.users.new) }}</small></div>
        <div class="stat glass"><span>С подпиской</span><b>{{ num(s.users.subscribed) }}</b><small>заблокировано: {{ num(s.users.blocked) }}</small></div>
        <div class="stat glass"><span>Генерации</span><b>{{ num(s.generations.total) }}</b><small>сейчас в работе: {{ num(s.generations.active) }}</small></div>
        <div class="stat glass"><span>Потрачено токенов</span><b>{{ num(s.generations.tokens) }}</b><small>за готовые результаты</small></div>
        <div class="stat glass"><span>Оплаты</span><b>{{ num(s.payments.count) }}</b><small>продано токенов: {{ num(s.payments.tokens) }}</small></div>
        <div class="stat glass"><span>Выручка</span><b>{{ num(s.payments.by_currency.RUB) }} ₽</b><small>и {{ num(s.payments.by_currency.XTR) }} ⭐</small></div>
      </div>
      <div class="adm-card glass">
        <h3>Генерации за 14 дней</h3>
        <div class="adm-chart" role="img" :aria-label="'Генерации по дням, максимум ' + max">
          <div v-for="d in s.series" :key="d.day" :title="d.day + ': ' + d.generations"><span>{{ d.generations || '' }}</span><i :style="{ height: Math.round(d.generations / max * 78) + '%' }" /><span>{{ d.day.slice(8) }}</span></div>
        </div>
      </div>
      <div class="adm-grid wide">
        <div class="adm-card glass">
          <h3>По типам</h3>
          <dl class="adm-kv"><template v-for="(n, k) in s.generations.by_kind" :key="k"><dt>{{ kinds[k] || k }}</dt><dd>{{ num(n) }}</dd></template></dl>
          <p v-if="!Object.keys(s.generations.by_kind).length" class="adm-note">За этот период генераций нет.</p>
        </div>
        <div class="adm-card glass">
          <h3>По статусам</h3>
          <dl class="adm-kv"><template v-for="(n, k) in s.generations.by_status" :key="k"><dt>{{ statuses[k] || k }}</dt><dd>{{ num(n) }}</dd></template></dl>
          <p v-if="!Object.keys(s.generations.by_status).length" class="adm-note">За этот период генераций нет.</p>
        </div>
      </div>
    </template>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section, PERIODS, periodRange, KINDS, STATUSES } from './shared'

export default {
  name: 'AdminDashboard',
  mixins: [section],
  data: () => ({ s: null, period: 'all', periods: PERIODS.filter((p) => p.id !== 'custom'), kinds: KINDS, statuses: STATUSES }),
  computed: { max() { return Math.max(1, ...(this.s?.series || []).map((d) => d.generations)) } },
  mounted() { this.load() },
  methods: {
    load() { return this.fetch(async () => { this.s = await adminApi.stats(periodRange(this.period)) }) },
  },
}
</script>
