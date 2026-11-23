<template>
  <section class="adm-sec">
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <button v-else-if="failed" class="adm-btn" type="button" @click="load">Повторить</button>
    <form v-else class="adm-card glass adm-grid" @submit.prevent="save">
      <h3 class="full">Автопродление через ЮKassa</h3>
      <label class="adm-check full"><input v-model="form.enabled" type="checkbox" :disabled="!canManage">Разрешить новые автосписания</label>
      <p v-if="!serverEnabled" class="adm-note full">Автосписания отключены в конфигурации сервера. Расписание можно подготовить заранее.</p>
      <p class="adm-note full">Активные сроки в магазине: {{ shopTerms.length ? shopTerms.join(', ') + ' дн.' : 'нет' }}. Если срока нет у нужного тарифа, попытка пропускается.</p>
      <fieldset v-for="(step, index) in form.steps" :key="index" class="adm-grid full renewal-step" :disabled="!canManage || busy">
        <legend>{{ index === 0 ? 'Первая попытка' : 'Попытка ' + (index + 1) }}</legend>
        <label class="adm-f">Дней подписки<input v-model.number="step.days" type="number" min="1" max="3660" required></label>
        <label class="adm-f">{{ index ? 'Часов после предыдущей попытки' : 'Часов после окончания подписки' }}<input v-model.number="step.wait_hours" type="number" :min="index ? 1 : 0" max="2160" required></label>
        <small class="adm-note">{{ hoursLabel(totalHours(index)) }} после окончания, если попытки идут по расписанию</small>
        <button v-if="index > 0" class="adm-btn danger sm" type="button" @click="form.steps.splice(index, 1)">Убрать попытку</button>
      </fieldset>
      <div v-if="canManage" class="adm-actions full">
        <button class="adm-btn soft" type="button" :disabled="busy || form.steps.length >= 8 || form.steps.at(-1).days <= 1" @click="add">Добавить попытку</button>
        <button class="adm-btn" type="submit" :disabled="busy">Сохранить</button>
      </div>
    </form>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section } from './shared'

export default {
  name: 'AdminRenewal',
  mixins: [section],
  data: () => ({ form: { enabled: true, steps: [] }, canManage: false, serverEnabled: false, shopTerms: [] }),
  mounted() { this.load() },
  methods: {
    accept(result) {
      this.form = result.data
      this.serverEnabled = result.server_enabled
      this.canManage = result.can_manage
      this.shopTerms = result.shop_terms
    },
    load() { return this.fetch(async () => this.accept(await adminApi.renewal())) },
    save() { return this.run(async () => this.accept(await adminApi.saveRenewal(this.form)), 'Расписание сохранено') },
    add() {
      const days = this.form.steps.at(-1).days
      this.form.steps.push({ days: this.shopTerms.find(term => term < days) ?? days - 1, wait_hours: 24 })
    },
    totalHours(index) { return this.form.steps.slice(0, index + 1).reduce((hours, item) => hours + Number(item.wait_hours || 0), 0) },
    hoursLabel(hours) {
      if (!hours) return 'Сразу'
      const days = Math.floor(hours / 24)
      const remainder = hours % 24
      return [days && days + ' дн.', remainder && remainder + ' ч.'].filter(Boolean).join(' ')
    },
  },
}
</script>

<style scoped>
.renewal-step {
  grid-template-columns: minmax(0, 1fr);
  align-items: end; min-width: 0; padding: 12px;
  border: 1px solid var(--color-rule); border-radius: var(--radius-ctl);
}
.renewal-step > .adm-btn { justify-self: start; }
.renewal-step legend { padding: 0 4px; }
@media (min-width: 580px) {
  .renewal-step { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
