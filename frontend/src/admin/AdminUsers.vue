<template>
  <section class="adm-sec">
    <div class="filters"><button v-for="(n, k) in tabs" :key="k" type="button" class="chip" :aria-pressed="String(tab === k)" @click="tab = k; reload()">{{ n }}</button></div>
    <div v-if="tab === 'users'" class="adm-bar">
      <label class="adm-f grow">Поиск<input v-model.trim="q" type="search" maxlength="64" placeholder="@username, имя или Telegram id" @keyup.enter="reload" @search="reload"></label>
      <label class="adm-f">Показать<select v-model="filter" @change="reload"><option value="">Всех</option><option value="paid">С подпиской</option><option value="blocked">Заблокированных</option></select></label>
    </div>
    <template v-else-if="tab === 'admins'" data-admin-search-context="admins">
      <p class="adm-note">Администраторы видят всю админку. Добавлять и убирать их может только главный администратор, его убрать нельзя.</p>
      <form v-if="canManage" class="adm-bar" @submit.prevent="addAdmin">
        <label class="adm-f grow">Кого добавить<input v-model.trim="newAdmin" required maxlength="64" placeholder="Telegram id или @username"></label>
        <button type="submit" class="adm-btn" style="align-self:end" :disabled="busy">Добавить</button>
      </form>
    </template>
    <p v-if="tab === 'users'" class="adm-note">Найдено: {{ num(total) }}</p>

    <div v-if="loading && !items.length" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed && !items.length" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="reload">Повторить</button></div>
    <div v-else-if="!items.length" class="empty pad"><h3>Ничего не найдено</h3></div>

    <div v-else-if="tab === 'users'">
      <div v-for="u in items" :key="u.id" :class="['adm-item', 'glass', u.is_blocked ? 'off' : '']">
        <button type="button" class="row" :aria-expanded="String(edit === u)" @click="toggle(u)">
          <span class="sq">{{ (u.name || '?')[0] }}</span>
          <span class="t"><b>{{ u.name }}<span v-if="u.is_admin" class="badge">админ</span></b><span>{{ u.username ? '@' + u.username + ' · ' : '' }}id {{ u.telegram_id }}</span></span>
          <span class="end"><span v-if="u.is_blocked" class="adm-pill bad">блок</span><span v-if="u.plan !== 'free'" class="adm-pill hot">{{ plans[u.plan] || u.plan }}</span>{{ num(u.balance) }} ток.</span>
        </button>
        <div v-if="edit === u" class="adm-edit" data-admin-search-context="account">
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
              <label class="adm-f">Тариф<select v-model="plan" required><option v-if="!plans[u.plan]" value="" disabled>Выберите тариф</option><option v-for="(n, k) in plans" :key="k" :value="k">{{ n }}</option></select></label>
              <label v-if="plan !== 'free'" class="adm-f">На сколько дней<input v-model.number="days" type="number" min="1" max="3660" required></label>
            </div>
            <div class="adm-actions"><button type="submit" class="adm-btn sm" :disabled="busy">Применить</button></div>
          </form>
          <div class="adm-actions">
            <button type="button" class="adm-btn soft sm" @click="$emit('content', String(u.telegram_id))">Работы пользователя</button>
            <button type="button" class="adm-btn soft sm" @click="$emit('payments', String(u.telegram_id))">Платежи пользователя</button>
            <button v-if="!u.is_admin" type="button" :class="['adm-btn', 'sm', u.is_blocked ? 'soft' : 'danger']" :disabled="busy" @click="block(u)">{{ u.is_blocked ? 'Разблокировать' : 'Заблокировать' }}</button>
          </div>
        </div>
      </div>
    </div>

    <ul v-else class="list glass">
      <li v-for="a in items" :key="a.telegram_id">
        <div class="row">
          <span :class="['sq', a.is_owner ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use :href="a.is_owner ? '#i-crown' : '#i-user'" /></svg></span>
          <span class="t"><b>{{ a.name || 'Ещё не открывал приложение' }}</b><span>{{ a.username ? '@' + a.username + ' · ' : '' }}id {{ a.telegram_id }}</span></span>
          <span class="end">
            <span v-if="a.is_owner" class="adm-pill hot">главный</span>
            <button v-else-if="canManage" type="button" class="adm-btn danger sm" :disabled="busy" @click="removeAdmin(a)">{{ confirmAdmin === a.telegram_id ? 'Точно убрать?' : 'Убрать' }}</button>
          </span>
        </div>
      </li>
    </ul>

    <button v-if="next" type="button" class="adm-btn soft" :disabled="loading" @click="load(next)">{{ loading ? 'Загружаем…' : 'Показать ещё' }}</button>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section } from './shared'

export default {
  name: 'AdminUsers',
  mixins: [section],
  props: { initialQuery: { type: String, default: '' }, searchContext: { type: String, default: '' } },
  emits: ['content', 'payments'],
  data() { return {
    tabs: { users: 'Пользователи', admins: 'Администраторы' }, tab: this.searchContext === 'admins' ? 'admins' : 'users', plans: {},
    q: this.initialQuery.slice(0, 64), filter: '', items: [], next: null, total: 0, edit: null, amount: 100, comment: '', plan: 'free', days: '', seq: 0, canManage: false, newAdmin: '', confirmAdmin: null,
  } },
  async mounted() {
    await this.reload()
    if (this.searchContext === 'account' && this.items.length) this.toggle(this.items[0])
  },
  methods: {
    reload() { this.items = []; this.next = null; this.edit = null; return this.load(1) },
    load(page) {
      const seq = ++this.seq
      return this.fetch(async () => {
        const r = this.tab === 'users' ? await adminApi.list('users', { q: this.q, filter: this.filter, page }) : await adminApi.list('admins')
        if (this.tab === 'admins') this.canManage = r.can_manage
        if (seq !== this.seq) return
        if (r.plans) this.plans = r.plans
        this.items = page === 1 ? r.data : [...this.items, ...r.data]
        this.next = r.next_page
        this.total = r.summary?.total ?? this.items.length
      })
    },
    toggle(u) {
      this.edit = this.edit === u ? null : u
      this.plan = this.plans[u.plan] ? u.plan : ''
      this.days = ''
      this.amount = 100
      this.comment = ''
    },
    replace(old, fresh) { const i = this.items.indexOf(old); this.items.splice(i, 1, fresh); this.edit = this.items[i]; if (fresh.id === this.app.user.id) this.app.refreshUser() },
    balance(u, sign) { return this.run(async () => { this.replace(u, (await adminApi.balance(u.id, sign * this.amount, this.comment || null)).data) }, sign > 0 ? 'Токены начислены' : 'Токены списаны') },
    setPlan(u) { return this.run(async () => { this.replace(u, (await adminApi.update('users', u.id, { plan: this.plan, plan_days: this.plan === 'free' ? null : this.days })).data) }, 'Подписка обновлена') },
    addAdmin() { return this.run(async () => { this.items = (await adminApi.create('admins', { user: this.newAdmin })).data; this.newAdmin = '' }, 'Администратор добавлен') },
    removeAdmin(a) {
      if (this.confirmAdmin !== a.telegram_id) { this.confirmAdmin = a.telegram_id; return }
      return this.run(async () => { this.items = (await adminApi.remove('admins', a.telegram_id)).data; this.confirmAdmin = null }, 'Доступ убран')
    },
    block(u) { return this.run(async () => { this.replace(u, (await adminApi.update('users', u.id, { is_blocked: !u.is_blocked })).data) }, u.is_blocked ? 'Разблокирован' : 'Заблокирован') },
  },
}
</script>
