<template>
  <div class="adm-sec">
    <!-- ---------- Stars rate ---------- -->
    <template v-if="view === 'stars'">
      <div v-if="!stars" class="spinner-row">{{ failed ? 'Не получилось загрузить' : 'Загружаем…' }}</div>
      <form v-else class="adm-card glass" @submit.prevent="saveStars">
        <h3>Курс Telegram Stars</h3>
        <div class="adm-grid">
          <label class="adm-check"><input v-model="stars.enabled" type="checkbox">Принимать оплату звёздами</label>
          <label class="adm-f">Рублей за 1 ⭐<input v-model.number="stars.rub_per_star" type="number" min="0.1" max="1000" step="0.01" required @input="perRub = rate(1 / (stars.rub_per_star || 1))"></label>
          <label class="adm-f">Звёзд за 1 ₽<input v-model.number="perRub" type="number" min="0.001" max="10" step="0.001" @input="stars.rub_per_star = rate(1 / (perRub || 1))"></label>
        </div>
        <p class="adm-note">Цена в звёздах считается из рублёвой цены товара (после промокода): рубли делятся на курс и округляются вверх. Сейчас 100 ₽ = {{ num(toStars(100)) }} ⭐.</p>
        <div v-if="items.length" class="adm-sub">
          <span class="ctl-label">Цены товаров при этом курсе</span>
          <div v-for="i in items.filter((x) => x.price > 0)" :key="i.id" class="adm-line" style="align-items:center">
            <span style="flex:1;min-width:0">{{ i.name }}</span><span class="adm-note">{{ num(i.price) }} ₽ → <b>{{ num(toStars(i.price)) }} ⭐</b></span>
          </div>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>
    </template>

    <!-- ---------- shop items and payment links ---------- -->
    <template v-else-if="view === 'links'">
      <div v-if="loading && !items.length" class="spinner-row">Загружаем…</div>
      <div v-else-if="!items.length" class="empty pad"><h3>В таблице shop нет товаров</h3><p>Товары добавляются в боте (таблица shop).</p></div>
      <template v-else>
        <p class="adm-note">Ссылка на оплату открывает страницу оплаты этого товара: в Telegram сразу с выбором способа и промокодом, в браузере с кнопкой «Оплатить в Telegram». Ссылку t.me можно ставить на кнопки бота и в посты.</p>
        <div v-for="i in items" :key="i.id" class="adm-item glass">
          <div class="row" style="cursor:default">
            <span :class="['sq', i.can_buy ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use :href="i.category === 'token' ? '#i-wallet' : '#i-crown'" /></svg></span>
            <span class="t"><b>{{ i.name }}</b><span>shop #{{ i.id }} · {{ i.category }} / {{ i.item }} · {{ num(i.amount) }}</span></span>
            <span class="end"><span :class="['adm-pill', i.can_buy ? 'ok' : 'bad']">{{ i.can_buy ? 'продаётся' : 'скрыт' }}</span>{{ num(i.price) }} ₽ · {{ num(i.stars) }} ⭐</span>
          </div>
          <div class="adm-edit">
            <div class="adm-actions">
              <button type="button" class="adm-btn soft sm" @click="copy(i.pay_link)">Копировать ссылку</button>
              <button v-if="i.telegram_link" type="button" class="adm-btn soft sm" @click="copy(i.telegram_link)">Копировать t.me</button>
              <span class="adm-note" style="word-break:break-all">{{ i.pay_link }}</span>
            </div>
          </div>
        </div>
      </template>
    </template>

    <!-- ---------- promo codes ---------- -->
    <template v-else>
      <div class="adm-actions"><button type="button" class="adm-btn" @click="edit(null)">Новый промокод</button></div>
      <form v-if="form && form.id === null" class="adm-card glass" @submit.prevent="savePromo">
        <h3>Новый промокод</h3>
        <PromoFields :f="form" :items="items" />
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Создать</button><button type="button" class="adm-btn soft" @click="form = null">Отмена</button></div>
      </form>

      <div v-if="loading && !promos.length" class="spinner-row">Загружаем…</div>
      <div v-else-if="failed && !promos.length" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
      <div v-else-if="!promos.length" class="empty pad"><h3>Промокодов пока нет</h3><p>Коды хранятся в таблице бота promos, так что бот видит те же коды.</p></div>
      <div v-for="p in promos" :key="p.id" class="adm-item glass">
        <button type="button" class="row" :aria-expanded="String(form && form.id === p.id)" @click="form && form.id === p.id ? (form = null) : edit(p)">
          <span :class="['sq', p.state === 'active' ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use href="#i-gift" /></svg></span>
          <span class="t"><b>{{ p.code }} · −{{ p.discount }}%</b><span>{{ limits(p) }}</span></span>
          <span class="end"><span :class="['adm-pill', p.state === 'active' ? 'ok' : p.state === 'scheduled' ? '' : 'bad']">{{ states[p.state] }}</span>{{ num(p.used) }} {{ plural(p.used) }}</span>
        </button>
        <form v-if="form && form.id === p.id" class="adm-edit" @submit.prevent="savePromo">
          <dl class="adm-kv">
            <dt>Использован</dt><dd>{{ num(p.used) }} раз, скидок на {{ num(p.discount_given) }} ₽, оплачено {{ num(p.revenue) }} ₽</dd>
          </dl>
          <PromoFields :f="form" :items="items" />
          <div class="adm-actions">
            <button type="submit" class="adm-btn" :disabled="busy">Сохранить</button>
            <button type="button" class="adm-btn soft" @click="form = null">Отмена</button>
            <button type="button" class="adm-btn danger" :disabled="busy" @click="removePromo(p)">{{ confirmDelete === p.id ? 'Точно удалить?' : 'Удалить' }}</button>
          </div>
        </form>
      </div>
    </template>
  </div>
</template>

<script>
import { adminApi } from '../services/api'
import { section } from './shared'
import PromoFields from './PromoFields.vue'

const blank = () => ({ id: null, code: '', discount: 10, uses: null, active: true, starts_at: '', expires_at: '', per_user: 1, min_price: null, item_ids: [], category: '', new_buyers_only: false, note: '' })
// <input type="datetime-local"> works with local time without a zone
const local = (iso) => { if (!iso) return ''; const d = new Date(iso); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16) }
const iso = (v) => (v ? new Date(v).toISOString() : null)

export default {
  name: 'AdminShopPromos',
  components: { PromoFields },
  mixins: [section],
  props: { view: { type: String, default: 'promos' } },
  data: () => ({
    items: [], promos: [], form: null, confirmDelete: null, stars: null, perRub: 0.5, rubPerStar: 2,
    states: { active: 'действует', off: 'выключен', expired: 'истёк', scheduled: 'ещё не начался', used_up: 'закончился' },
  }),
  watch: { view() { this.load() } },
  mounted() { this.load() },
  methods: {
    rate: (v) => Math.round(v * 1000) / 1000,
    toStars(rub) { return Math.max(1, Math.ceil(rub / (Number(this.stars?.rub_per_star) || this.rubPerStar || 1))) },
    plural(n) { const m = n % 10, h = n % 100; return m === 1 && h !== 11 ? 'раз' : m >= 2 && m <= 4 && (h < 12 || h > 14) ? 'раза' : 'раз' },
    load() {
      return this.fetch(async () => {
        const shop = await adminApi.list('shop')
        this.items = shop.data
        this.rubPerStar = shop.rub_per_star
        if (this.view === 'promos') this.promos = (await adminApi.list('promos')).data
        if (this.view === 'stars') {
          const s = (await adminApi.settings()).data.stars
          this.stars = { enabled: !!s.enabled, rub_per_star: Number(s.rub_per_star) }
          this.perRub = this.rate(1 / (this.stars.rub_per_star || 1))
        }
      })
    },
    limits(p) {
      const out = []
      out.push(p.uses === null ? 'без лимита' : `осталось ${this.num(p.uses)}`)
      if (p.per_user) out.push(`${p.per_user} на человека`)
      if (p.expires_at) out.push(`до ${this.when(p.expires_at)}`)
      if (p.item_ids.length) out.push(`товаров: ${p.item_ids.length}`)
      else if (p.category) out.push(p.category === 'token' ? 'только токены' : p.category === 'sub' ? 'только подписки' : p.category)
      if (p.min_price) out.push(`от ${this.num(p.min_price)} ₽`)
      if (p.new_buyers_only) out.push('первая покупка')
      return out.join(' · ')
    },
    edit(p) {
      this.confirmDelete = null
      this.form = p ? { ...blank(), ...p, starts_at: local(p.starts_at), expires_at: local(p.expires_at), item_ids: [...p.item_ids], category: p.category || '', note: p.note || '' } : blank()
    },
    savePromo() {
      const f = this.form
      const body = {
        code: f.code, discount: f.discount, uses: f.uses === '' || f.uses === null ? null : f.uses, active: f.active,
        starts_at: iso(f.starts_at), expires_at: iso(f.expires_at), per_user: f.per_user || null, min_price: f.min_price || null,
        item_ids: f.item_ids, category: f.category || null, new_buyers_only: f.new_buyers_only, note: f.note || null,
      }
      return this.run(async () => {
        const r = f.id ? await adminApi.update('promos', f.id, body) : await adminApi.create('promos', body)
        const i = this.promos.findIndex((x) => x.id === r.data.id)
        if (i >= 0) this.promos.splice(i, 1, r.data)
        else this.promos.unshift(r.data)
        this.form = null
      }, f.id ? 'Промокод сохранён' : 'Промокод создан')
    },
    removePromo(p) {
      if (this.confirmDelete !== p.id) { this.confirmDelete = p.id; return }
      return this.run(async () => {
        await adminApi.remove('promos', p.id)
        this.promos = this.promos.filter((x) => x.id !== p.id)
        this.form = null
      }, 'Промокод удалён')
    },
    saveStars() {
      return this.run(async () => {
        const s = (await adminApi.saveSettings({ stars: { enabled: this.stars.enabled, rub_per_star: this.stars.rub_per_star } })).data.stars
        this.stars = { enabled: !!s.enabled, rub_per_star: Number(s.rub_per_star) }
        this.rubPerStar = this.stars.rub_per_star
        this.app.reloadCatalog()
      }, 'Курс сохранён')
    },
    async copy(text) {
      try { await navigator.clipboard.writeText(text); this.app.toast('Ссылка скопирована') } catch { this.app.toast(text) }
    },
  },
}
</script>
