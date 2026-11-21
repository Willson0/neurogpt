<template>
  <section class="adm-sec">
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else>
      <section class="adm-card glass">
        <h3>Пакеты токенов</h3>
        <p class="adm-note">Предложения из таблицы shop бота.</p>
        <div v-for="p in v.packs" :key="p.id" class="adm-sub">
          <b>{{ p.name }}</b>
          <span class="adm-note">{{ p.tokens }} ток. · {{ p.price }} ₽ · {{ p.stars }} ⭐</span>
        </div>
        <p v-if="!v.packs?.length" class="adm-note">Активных пакетов нет.</p>
      </section>

      <section class="adm-card glass">
        <h3>Подписки</h3>
        <p class="adm-note">Предложения из таблицы shop бота.</p>
        <div v-for="offer in v.subscription_offers" :key="offer.id" class="adm-sub">
          <b>{{ offer.name }}</b>
          <span class="adm-note">{{ offer.days }} дн. · {{ offer.price }} ₽ · {{ offer.stars }} ⭐</span>
        </div>
        <p v-if="!v.subscription_offers?.length" class="adm-note">Активных предложений нет.</p>
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
  data: () => ({ v: null }),
  mounted() { this.load() },
  methods: {
    load() { return this.fetch(async () => { this.v = (await adminApi.settings()).data }) },
    save(keys, ok) {
      const values = Object.fromEntries(keys.map((k) => [k, this.v[k]]))
      return this.run(async () => { this.v = (await adminApi.saveSettings(values)).data; this.app.reloadCatalog() }, ok)
    },
  },
}
</script>
