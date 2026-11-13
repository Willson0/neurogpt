<template>
  <section class="adm-sec">
    <div class="filters"><button v-for="(n, k) in tabs" :key="k" type="button" class="chip" :aria-pressed="String(tab === k)" @click="tab = k; reload()">{{ n }}</button></div>
    <div v-if="tab === 'users'" class="adm-bar">
      <label class="adm-f grow">Поиск<input v-model.trim="q" type="search" placeholder="@username, имя или Telegram id" @keyup.enter="reload" @search="reload"></label>
      <label class="adm-f">Показать<select v-model="filter" @change="reload"><option value="">Всех</option><option value="paid">С подпиской</option><option value="blocked">Заблокированных</option></select></label>
    </div>
    <div v-else class="adm-bar">
      <label class="adm-f">Статус<select v-model="status" @change="reload"><option value="">Любой</option><option value="succeeded">Оплачен</option><option value="pending">Ожидает</option><option value="canceled">Отменён</option></select></label>
    </div>
    <p v-if="tab === 'users'" class="adm-note">Найдено: {{ num(total) }}</p>

    <div v-if="loading && !items.length" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed && !items.length" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="reload">Повторить</button></div>
    <div v-else-if="!items.length" class="empty pad"><h3>Ничего не найдено</h3></div>

    <div v-else-if="tab === 'users'">
      <div v-for="u in items" :key="u.id" :class="['adm-item', 'glass', u.is_blocked ? 'off' : '']">
        <button type="button" class="row" :aria-expanded="String(edit === u)" @click="toggle(u)">
          <span class="sq">{{ (u.name || '?')[0] }}</span>
          <span class="t"><b>{{ u.name }}<span v-if="u.is_admin" class="badge">админ</span></b><span>{{ u.username ? '@' + u.username + ' · ' : '' }}id {{ u.telegram_id }}</span></span>
          <span class="end"><span v-if="u.is_blocked" class="adm-pill bad">блок</span><span v-if="u.plan !== 'free'" class="adm-pill hot">{{ plans[u.plan] }}</span>{{ num(u.balance) }} ток.</span>
        </button>
        <div v-if="edit === u" class="adm-edit">
          <dl class="adm-kv">
            <dt>В приложении с</dt><dd>{{ when(u.created_at) }}</dd>
            <dt>Готовых работ</dt><dd>{{ num(u.created) }}</dd>
            <template v-if="u.plan_expires_at"><dt>Подписка до</dt><dd>{{ when(u.plan_expires_at) }}</dd></template>
          </dl>
          <form class="adm-sub" @submit.prevent>
            <span class="ctl-label">Баланс</span>
            <div class="adm-line">
              <label class="adm-f">Токенов<input v-model.number="amount" type="number" min="1" required></label>
              <label class="adm-f" style="grid-column:span 2">Комментарий в истории<input v-model.trim="comment" maxlength="120" placeholder="Необязательно"></label>
            </div>
            <div class="adm-actions"><button type="button" class="adm-btn sm" :disabled="busy || !(amount > 0)" @click="balance(u, 1)">Начислить</button><button type="button" class="adm-btn soft sm" :disabled="busy || !(amount > 0)" @click="balance(u, -1)">Списать</button></div>
          </form>
          <form class="adm-sub" @submit.prevent="setPlan(u)">
            <span class="ctl-label">Подписка</span>
            <div class="adm-line">
              <label class="adm-f">Тариф<select v-model="plan"><option v-for="(n, k) in plans" :key="k" :value="k">{{ n }}</option></select></label>
              <label v-if="plan !== 'free'" class="adm-f">На сколько дней<input v-model.number="days" type="number" min="1" max="3660" required></label>
            </div>
            <div class="adm-actions"><button type="submit" class="adm-btn sm" :disabled="busy">Применить</button></div>
          </form>
          <div class="adm-actions">
            <button type="button" class="adm-btn soft sm" @click="$emit('content', String(u.telegram_id))">Работы пользователя</button>
            <button v-if="!u.is_admin" type="button" :class="['adm-btn', 'sm', u.is_blocked ? 'soft' : 'danger']" :disabled="busy" @click="block(u)">{{ u.is_blocked ? 'Разблокировать' : 'Заблокировать' }}</button>
          </div>
        </div>
      </div>
    </div>

    <ul v-else class="list glass">
      <li v-for="p in items" :key="p.id">
        <div class="row">
          <span :class="['sq', p.status === 'succeeded' ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use :href="p.purpose === 'plan' ? '#i-crown' : '#i-wallet'" /></svg></span>
          <span class="t"><b>{{ p.purpose === 'plan' ? `Подписка ${plans[p.plan] || p.plan}, ${p.period === 'year' ? 'год' : 'месяц'}` : `${num(p.tokens)} токенов` }}</b><span>{{ who(p.user) }} · {{ when(p.created_at) }}</span></span>
          <span class="end"><span :class="['adm-pill', p.status === 'succeeded' ? 'ok' : p.status === 'canceled' ? 'bad' : '']">{{ payStatus[p.status] }}</span>{{ num(p.amount) }} {{ p.currency === 'XTR' ? '⭐' : '₽' }}</span>
        </div>
      </li>
    </ul>
    <button v-if="next" type="button" class="adm-btn soft" :disabled="loading" @click="load(next)">{{ loading ? 'Загружаем…' : 'Показать ещё' }}</button>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section, PLANS } from './shared'

export default {
  name: 'AdminUsers',
  mixins: [section],
  emits: ['content'],
  data: () => ({
    tabs: { users: 'Пользователи', payments: 'Платежи' }, tab: 'users', plans: PLANS,
    payStatus: { succeeded: 'оплачен', pending: 'ожидает', canceled: 'отменён' },
    q: '', filter: '', status: '', items: [], next: null, total: 0, edit: null, amount: 100, comment: '', plan: 'free', days: 30, seq: 0,
  }),
  mounted() { this.reload() },
  methods: {
    reload() { this.items = []; this.next = null; this.edit = null; return this.load(1) },
    load(page) {
      const seq = ++this.seq
      return this.fetch(async () => {
        const r = this.tab === 'users' ? await adminApi.list('users', { q: this.q, filter: this.filter, page }) : await adminApi.list('payments', { status: this.status, page })
        if (seq !== this.seq) return
        this.items = page === 1 ? r.data : [...this.items, ...r.data]
        this.next = r.next_page
        this.total = r.summary?.total ?? this.items.length
      })
    },
    toggle(u) {
      this.edit = this.edit === u ? null : u
      this.plan = u.plan
      this.amount = 100
      this.comment = ''
    },
    replace(old, fresh) { const i = this.items.indexOf(old); this.items.splice(i, 1, fresh); this.edit = this.items[i]; if (fresh.id === this.app.user.id) this.app.refreshUser() },
    balance(u, sign) { return this.run(async () => { this.replace(u, (await adminApi.balance(u.id, sign * this.amount, this.comment || null)).data) }, sign > 0 ? 'Токены начислены' : 'Токены списаны') },
    setPlan(u) { return this.run(async () => { this.replace(u, (await adminApi.update('users', u.id, { plan: this.plan, plan_days: this.days })).data) }, 'Подписка обновлена') },
    block(u) { return this.run(async () => { this.replace(u, (await adminApi.update('users', u.id, { is_blocked: !u.is_blocked })).data) }, u.is_blocked ? 'Разблокирован' : 'Заблокирован') },
  },
}
</script>
