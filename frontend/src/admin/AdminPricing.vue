<template>
  <section class="adm-sec">
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else>
      <form class="adm-card glass" @submit.prevent="save(['packs'], 'Пакеты сохранены')">
        <h3>Пакеты токенов</h3>
        <span class="adm-note">Если в таблице бота shop есть активные товары категории token, приложение продаёт их, а эти пакеты не показываются.</span>
        <div v-for="(p, i) in v.packs" :key="i" class="adm-line">
          <label class="adm-f">Токенов<input v-model.number="p.tokens" type="number" min="1" required></label>
          <label class="adm-f">Цена, ₽<input v-model.number="p.price" type="number" min="1" step="any" required></label>
          <label class="adm-f">Плашка<input v-model.trim="p.badge" maxlength="24" placeholder="Выгодно"></label>
          <span class="adm-note" style="align-self:center">{{ stars(p.price) }} ⭐</span>
          <button type="button" class="x" aria-label="Убрать пакет" @click="v.packs.splice(i, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button><button type="button" class="adm-btn soft" @click="v.packs.push({ tokens: 1000, price: 900, badge: '' })">Добавить пакет</button></div>
      </form>

      <form class="adm-card glass" @submit.prevent="save(['plans'], 'Тарифы сохранены')">
        <h3>Подписки</h3>
        <span class="adm-note">Цена и срок подписки берутся из товаров shop категории sub (item = plus или pro), если они есть. Токены в месяц всегда отсюда.</span>
        <div v-for="id in ['plus', 'pro']" :key="id" class="adm-sub">
          <span class="ctl-label">Тариф {{ id }}</span>
          <div class="adm-grid">
            <label class="adm-f">Название<input v-model.trim="v.plans[id].name" required maxlength="24"></label>
            <label class="adm-f">Цена за месяц, ₽<input v-model.number="v.plans[id].month" type="number" min="0" step="any" required></label>
            <label class="adm-f">Месяц при оплате за год, ₽<input v-model.number="v.plans[id].year_month" type="number" min="0" step="any" required></label>
            <label class="adm-f">Токенов в месяц<input v-model.number="v.plans[id].tokens" type="number" min="0" required></label>
          </div>
          <span class="adm-note">В звёздах: {{ stars(v.plans[id].month) }} ⭐ за месяц, {{ stars(v.plans[id].year_month * 12) }} ⭐ за год</span>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>

      <form class="adm-card glass" @submit.prevent="save(['bonus'], 'Бонусы сохранены')">
        <h3>Бонусы</h3>
        <div class="adm-grid">
          <label class="adm-f">За регистрацию<input v-model.number="v.bonus.signup" type="number" min="0" required></label>
          <label class="adm-f">Ежедневный<input v-model.number="v.bonus.daily" type="number" min="0" required></label>
          <label class="adm-f">Рефоводу с покупок друга, %<input v-model.number="v.bonus.referral_percent" type="number" min="0" max="100" required></label>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>

      <p class="adm-note">Курс Telegram Stars, промокоды и ссылки на оплату товаров: раздел «Платежи и ЮKassa».</p>
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
    stars(rub) { return Math.max(1, Math.ceil((Number(rub) || 0) / (Number(this.v.stars.rub_per_star) || 1))).toLocaleString('ru-RU') },
    save(keys, ok) {
      const values = Object.fromEntries(keys.map((k) => [k, k === 'plans' ? { plus: this.v.plans.plus, pro: this.v.plans.pro } : this.v[k]]))
      return this.run(async () => { this.v = (await adminApi.saveSettings(values)).data; this.app.reloadCatalog() }, ok)
    },
  },
}
</script>
