<template>
  <section class="adm-sec">
    <div class="filters"><button v-for="(n, k) in tabs" :key="k" type="button" class="chip" :aria-pressed="String(tab === k)" @click="tab = k">{{ n }}</button></div>

    <!-- ---------- payments ---------- -->
    <template v-if="tab === 'list'">
      <div class="filters" v-drag-scroll>
        <button v-for="p in periods" :key="p.id" type="button" class="chip" :aria-pressed="String(period === p.id)" @click="period = p.id; p.id !== 'custom' && reload()">{{ p.name }}</button>
      </div>
      <div class="adm-bar">
        <template v-if="period === 'custom'">
          <label class="adm-f">С<input v-model="from" type="date" @change="reload"></label>
          <label class="adm-f">По<input v-model="to" type="date" @change="reload"></label>
        </template>
        <label class="adm-f grow">Кто или какой платёж<input v-model.trim="user" type="search" placeholder="@username, Telegram id, номер или id в ЮKassa" @keyup.enter="reload" @search="reload"></label>
        <label class="adm-f">Статус<select v-model="status" @change="reload"><option value="">Любой</option><option v-for="(n, k) in statuses" :key="k" :value="k">{{ n }}</option></select></label>
        <label class="adm-f">Источник<select v-model="provider" @change="reload"><option value="">Любой</option><option value="yookassa">ЮKassa</option><option value="stars">Telegram Stars</option><option value="bot">Бот</option></select></label>
        <label class="adm-f">Что купили<select v-model="purpose" @change="reload"><option value="">Всё</option><option value="topup">Токены</option><option value="plan">Подписка</option></select></label>
      </div>
      <div class="adm-stats">
        <div class="stat glass"><span>Оплачено</span><b>{{ num(sum.succeeded) }}</b><small>из {{ num(sum.total) }} платежей</small></div>
        <div class="stat glass"><span>Выручка</span><b>{{ num(sum.by_currency.RUB) }} ₽</b><small>и {{ num(sum.by_currency.XTR) }} ⭐</small></div>
        <div class="stat glass"><span>Продано токенов</span><b>{{ num(sum.tokens) }}</b><small>плательщиков: {{ num(sum.payers) }}</small></div>
        <div class="stat glass"><span>Не завершены</span><b>{{ num(sum.pending) }}</b><small>отменено: {{ num(sum.canceled) }}</small></div>
      </div>

      <div v-if="loading && !items.length" class="spinner-row">Загружаем…</div>
      <div v-else-if="failed && !items.length" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="reload">Повторить</button></div>
      <div v-else-if="!items.length" class="empty pad"><h3>Платежей нет</h3><p>Измените период или фильтры.</p></div>
      <div v-else>
        <div v-for="p in items" :key="p.id" class="adm-item glass">
          <button type="button" class="row" :aria-expanded="String(open === p.id)" @click="open = open === p.id ? null : p.id">
            <span :class="['sq', p.status === 'succeeded' ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use :href="p.purpose === 'plan' ? '#i-crown' : '#i-wallet'" /></svg></span>
            <span class="t"><b>{{ title(p) }}</b><span>{{ who(p.user) }} · {{ when(p.created_at) }}</span></span>
            <span class="end"><span :class="['adm-pill', p.status === 'succeeded' ? 'ok' : p.status === 'canceled' ? 'bad' : '']">{{ statuses[p.status] }}</span>{{ money(p) }}</span>
          </button>
          <div v-if="open === p.id" class="adm-edit">
            <dl class="adm-kv">
              <dt>Номер</dt><dd>{{ p.id }}</dd>
              <dt>Покупатель</dt><dd>{{ p.user ? `${p.user.name}${p.user.username ? ' (@' + p.user.username + ')' : ''}, Telegram id ${p.user.telegram_id}` : 'удалён' }}</dd>
              <dt>Сумма</dt><dd>{{ money(p) }}<template v-if="p.income !== null">, к зачислению после комиссии {{ num(p.income) }} ₽</template></dd>
              <dt>Источник</dt><dd>{{ p.provider === 'bot' ? 'Бот' : p.provider === 'stars' ? 'Telegram Stars' : 'ЮKassa' }}<template v-if="p.method_title">, {{ p.method_title }}</template><template v-if="p.test"> (тестовый платёж)</template></dd>
              <dt>Что купили</dt><dd>{{ title(p) }}<template v-if="p.purpose === 'plan' && p.tokens">, {{ num(p.tokens) }} токенов</template></dd>
              <dt>Создан</dt><dd>{{ when(p.created_at) }}</dd>
              <template v-if="p.credited_at"><dt>Зачислен</dt><dd>{{ when(p.credited_at) }}</dd></template>
              <template v-if="p.provider_payment_id"><dt>ID платежа</dt><dd>{{ p.provider_payment_id }}</dd></template>
              <template v-if="p.cancel_reason"><dt>Причина отмены</dt><dd>{{ reasons[p.cancel_reason] || p.cancel_reason }}</dd></template>
            </dl>
            <div class="adm-actions">
              <button v-if="p.status === 'pending' && p.provider === 'yookassa'" type="button" class="adm-btn sm" :disabled="busy" @click="sync(p)">Проверить в ЮKassa</button>
              <button v-if="p.user" type="button" class="adm-btn soft sm" @click="user = String(p.user.telegram_id); reload()">Все платежи покупателя</button>
            </div>
          </div>
        </div>
      </div>
      <button v-if="next" type="button" class="adm-btn soft" :disabled="loading" @click="load(next)">{{ loading ? 'Загружаем…' : 'Показать ещё' }}</button>
    </template>

    <!-- ---------- YooKassa ---------- -->
    <template v-else>
      <div v-if="!shop" class="spinner-row">{{ shopFailed ? 'Не получилось загрузить' : 'Загружаем…' }}</div>
      <template v-else>
        <div class="adm-card glass">
          <h3>Состояние</h3>
          <dl class="adm-kv">
            <dt>Оплата картой</dt><dd>{{ shop.methods.card ? 'включена' : 'выключена: не заданы shopId и секретный ключ' }}</dd>
            <dt>Секретный ключ</dt><dd>{{ shop.has_secret ? `задан (${shop.secret_hint}), ${shop.secret_source === 'admin' ? 'сохранён в админке' : 'взят из .env'}${shop.test_mode ? ', тестовый магазин' : ''}` : 'не задан' }}</dd>
            <dt>Адрес для уведомлений</dt><dd>{{ shop.webhook_url }}</dd>
          </dl>
          <p class="adm-note">Этот адрес укажите в личном кабинете ЮKassa: «Интеграция», «HTTP-уведомления», события payment.succeeded и payment.canceled.</p>
          <div v-if="canManage" class="adm-actions">
            <button type="button" class="adm-btn soft sm" :disabled="busy" @click="check">Проверить подключение</button>
            <span v-if="checked" :class="['adm-pill', checked.ok ? 'ok' : 'bad']" style="white-space:normal">{{ checked.text }}</span>
          </div>
        </div>

        <form class="adm-card glass" @submit.prevent="saveShop">
          <h3>Магазин</h3>
          <p v-if="!canManage" class="adm-note">Менять платёжные настройки может только главный администратор.</p>
          <fieldset :disabled="!canManage" style="border:0;padding:0;margin:0;display:grid;gap:var(--space-sm);min-width:0">
            <div class="adm-grid wide">
              <label class="adm-f">shopId<input v-model.trim="f.shop_id" inputmode="numeric" pattern="[0-9]+" maxlength="32" placeholder="123456" autocomplete="off"></label>
              <label class="adm-f">Секретный ключ<input v-model.trim="f.secret_key" type="password" minlength="16" maxlength="255" autocomplete="new-password" :placeholder="shop.has_secret ? 'Оставьте пустым, чтобы не менять' : 'live_… или test_…'"></label>
              <label class="adm-f full">Куда вернуть человека после оплаты<input v-model.trim="f.return_url" type="url" pattern="https://.+" title="Адрес должен начинаться с https://" maxlength="255" placeholder="https://app.example.com"></label>
              <label class="adm-check"><input v-model="f.verify_ip" type="checkbox">Принимать уведомления только с адресов ЮKassa</label>
            </div>
            <div class="adm-sub">
              <span class="ctl-label">Чеки (54-ФЗ)</span>
              <label class="adm-check"><input v-model="f.receipt.enabled" type="checkbox">Отправлять чек с каждым платежом</label>
              <div v-if="f.receipt.enabled" class="adm-grid wide">
                <label class="adm-f">E-mail для чеков<input v-model.trim="f.receipt.email" type="email" maxlength="120" required></label>
                <label class="adm-f">Система налогообложения<select v-model.number="f.receipt.tax_system_code"><option v-for="(n, k) in taxSystems" :key="k" :value="Number(k)">{{ n }}</option></select></label>
                <label class="adm-f">Ставка НДС<select v-model.number="f.receipt.vat_code"><option v-for="(n, k) in vatCodes" :key="k" :value="Number(k)">{{ n }}</option></select></label>
              </div>
            </div>
            <p class="adm-note">Секретный ключ хранится на сервере в зашифрованном виде и в админке не показывается. После сохранения нажмите «Проверить подключение».</p>
            <div v-if="canManage" class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
          </fieldset>
        </form>
      </template>
    </template>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section, PERIODS, periodRange } from './shared'

export default {
  name: 'AdminPayments',
  mixins: [section],
  props: { initialUser: { type: String, default: '' } },
  data() {
    return {
      tabs: { list: 'Платежи', shop: 'Настройка ЮKassa' }, tab: 'list', periods: PERIODS,
      statuses: { succeeded: 'оплачен', pending: 'ожидает', canceled: 'отменён' },
      reasons: { create_failed: 'не удалось создать платёж', amount_mismatch: 'сумма не совпала', expired: 'не оплачен вовремя', expired_on_confirmation: 'не оплачен вовремя', canceled_by_merchant: 'отменён магазином', insufficient_funds: 'недостаточно средств', card_expired: 'истёк срок карты', '3d_secure_failed': 'не пройдена проверка 3-D Secure', general_decline: 'отказ банка', fraud_suspected: 'подозрение на мошенничество', permission_revoked: 'покупатель отозвал разрешение' },
      taxSystems: { 1: 'ОСН', 2: 'УСН доходы', 3: 'УСН доходы минус расходы', 4: 'ЕНВД', 5: 'ЕСХН', 6: 'Патент' },
      vatCodes: { 1: 'Без НДС', 2: '0%', 3: '10%', 4: '20%', 5: '10/110', 6: '20/120', 7: '5%', 8: '7%', 9: '5/105', 10: '7/107', 11: '22%', 12: '22/122' },
      period: 'all', from: '', to: '', user: this.initialUser, status: '', provider: '', purpose: '',
      items: [], next: null, open: null, seq: 0, sum: { total: 0, succeeded: 0, pending: 0, canceled: 0, tokens: 0, payers: 0, by_currency: {} },
      shop: null, shopFailed: false, canManage: false, checked: null,
      f: { shop_id: '', secret_key: '', return_url: '', verify_ip: true, receipt: { enabled: false, email: '', tax_system_code: 1, vat_code: 1 } },
    }
  },
  watch: { tab(t) { if (t === 'shop' && !this.shop) this.loadShop() } },
  mounted() { this.reload() },
  methods: {
    title(p) {
      if (p.purpose === 'other') return p.item_name || 'Покупка'
      if (p.purpose !== 'plan') return `${this.num(p.tokens)} токенов`
      const duration = p.plan_days ? `${p.plan_days} дн.` : p.period === 'year' ? 'год' : 'месяц'
      return `Подписка ${p.plan_name || p.plan}, ${duration}`
    },
    money(p) { return `${this.num(p.amount)} ${p.currency === 'XTR' ? '⭐' : p.currency === 'RUB' ? '₽' : p.currency}` },
    reload() { this.items = []; this.next = null; this.open = null; return this.load(1) },
    load(page) {
      const seq = ++this.seq
      return this.fetch(async () => {
        const r = await adminApi.list('payments', { ...periodRange(this.period, this.from, this.to), user: this.user.replace(/^@/, ''), status: this.status, provider: this.provider, purpose: this.purpose, page })
        if (seq !== this.seq) return
        this.items = page === 1 ? r.data : [...this.items, ...r.data]
        this.next = r.next_page
        this.sum = { ...r.summary, by_currency: r.summary.by_currency || {} }
      })
    },
    sync(p) {
      return this.run(async () => {
        const fresh = (await adminApi.syncPayment(p.id)).data
        this.items.splice(this.items.indexOf(p), 1, fresh)
        this.app.toast(fresh.status === 'pending' ? 'ЮKassa ещё ждёт оплату' : fresh.status === 'succeeded' ? 'Оплачен, токены зачислены' : 'Платёж отменён')
      })
    },
    take(r) {
      this.shop = r.data
      this.canManage = r.can_manage
      this.f = { shop_id: r.data.shop_id, secret_key: '', return_url: r.data.return_url, verify_ip: r.data.verify_ip, receipt: { ...r.data.receipt, email: r.data.receipt.email || '' } }
    },
    async loadShop() {
      this.shopFailed = false
      try { this.take(await adminApi.yookassa()) } catch { this.shopFailed = true }
    },
    saveShop() {
      const body = { ...this.f, shop_id: this.f.shop_id || null, secret_key: this.f.secret_key || null, return_url: this.f.return_url || null, receipt: { ...this.f.receipt, email: this.f.receipt.email || null } }
      return this.run(async () => { this.take(await adminApi.saveYookassa(body)); this.checked = null; this.app.reloadCatalog() }, 'Настройки ЮKassa сохранены')
    },
    check() {
      return this.run(async () => {
        const r = await adminApi.checkYookassa()
        this.checked = r.ok ? { ok: true, text: `Подключение работает, магазин ${r.account_id}${r.test ? ' (тестовый)' : ''}` } : { ok: false, text: r.message }
      })
    },
  },
}
</script>
