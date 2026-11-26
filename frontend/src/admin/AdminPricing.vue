<template>
  <section class="adm-sec">
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else>
      <section v-for="group in groups" :key="group.id" class="adm-card glass">
        <h3>{{ group.title }}</h3>
        <p class="adm-note">Изменения действуют в вебаппе и боте.</p>
        <div v-for="offer in offers.filter(item => item.category === group.id)" :key="offer.id" class="adm-offer">
          <form v-if="draft?.id === offer.id" class="adm-offer-form" @submit.prevent="saveOffer">
            <label class="adm-f">Название<input v-model.trim="draft.text_name" required maxlength="128"></label>
            <label class="adm-f">{{ group.amount }}<input v-model.number="draft.amount" type="number" min="1" :max="group.id === 'token' ? 10000000 : 3650" required></label>
            <label class="adm-f">Цена, ₽<input v-model.number="draft.price" type="number" min="1" max="10000000" required></label>
            <label class="adm-check"><input v-model="draft.active" type="checkbox">Доступно для покупки</label>
            <div class="adm-actions">
              <button type="submit" class="adm-btn" :disabled="busy">Сохранить пакет</button>
              <button type="button" class="adm-btn soft" :disabled="busy" @click="draft = null">Отменить</button>
            </div>
          </form>
          <template v-else>
            <div class="adm-offer-info">
              <b>{{ offer.text_name }}</b>
              <span class="adm-note">{{ offer.amount }} {{ group.unit }} · {{ offer.price }} ₽ · {{ offer.stars }} Stars{{ offer.active ? '' : ' · скрыт' }}</span>
            </div>
            <button type="button" class="adm-btn soft sm" :disabled="busy || !!draft" @click="draft = { ...offer }">Изменить</button>
          </template>
        </div>
        <p v-if="!offers.some(item => item.category === group.id)" class="adm-note">Предложений нет.</p>
      </section>

      <form class="adm-card glass" @submit.prevent="save(['bonus'], 'Бонусы сохранены')">
        <h3>Бонусы, токенов</h3>
        <div class="adm-grid">
          <label class="adm-f">За регистрацию<input v-model.number="v.bonus.signup" type="number" min="0" required></label>
          <label class="adm-f">Ежедневный<input v-model.number="v.bonus.daily" type="number" min="0" required></label>
          <label class="adm-f">За приглашённого друга<input v-model.number="v.bonus.referral" type="number" min="0" required></label>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>

      <form class="adm-card glass" @submit.prevent="save(['stars'], 'Сохранено')">
        <h3>Telegram Stars</h3>
        <div class="adm-grid">
          <label class="adm-check"><input v-model="v.stars.enabled" type="checkbox">Принимать оплату звёздами</label>
          <label class="adm-f">Рублей за одну звезду<input v-model.number="v.stars.rub_per_star" type="number" min="0.1" step="0.01" required></label>
        </div>
        <p class="adm-note">Цена в звёздах считается из рублёвой: рубли делятся на курс и округляются вверх.</p>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>
    </template>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section } from './shared'

export default {
  name: 'AdminPricing',
  mixins: [section],
  data: () => ({
    v: null, offers: [], draft: null,
    groups: [
      { id: 'token', title: 'Пакеты токенов', amount: 'Токенов', unit: 'ток.' },
      { id: 'subscription', title: 'Подписки', amount: 'Дней', unit: 'дн.' },
    ],
  }),
  mounted() { this.load() },
  methods: {
    load() {
      return this.fetch(async () => {
        const [settings, shop] = await Promise.all([adminApi.settings(), adminApi.list('shop')])
        this.v = settings.data
        this.offers = shop.data
      })
    },
    saveOffer() {
      return this.run(async () => {
        const { id, text_name, amount, price, active } = this.draft
        const { data } = await adminApi.update('shop', id, { text_name, amount, price, active })
        this.offers = this.offers.map(item => item.id === id ? data : item).sort((a, b) => a.price - b.price || a.id - b.id)
        this.draft = null
        this.app.reloadCatalog()
      }, 'Пакет сохранён')
    },
    save(keys, ok) {
      const values = Object.fromEntries(keys.map((k) => [k, this.v[k]]))
      return this.run(async () => { this.v = (await adminApi.saveSettings(values)).data; this.offers = (await adminApi.list('shop')).data; this.app.reloadCatalog() }, ok)
    },
  },
}
</script>
