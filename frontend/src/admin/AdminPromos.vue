<template>
  <section class="adm-sec">
    <form class="adm-card glass adm-grid" @submit.prevent="create">
      <label class="adm-f">Код<input v-model.trim="form.name" maxlength="64" pattern="[A-Za-z0-9_-]+" required></label>
      <label class="adm-f">Тип скидки<select v-model="form.type"><option value="percent">Процент</option><option value="fixed">Сумма в рублях</option></select></label>
      <label class="adm-f">Размер скидки<input v-model.number="form.discount" type="number" min="1" :max="form.type === 'percent' ? 100 : 1000000" required></label>
      <label class="adm-f">Использований<input v-model.number="form.uses" type="number" min="1" max="1000000" required></label>
      <button class="adm-btn" type="submit" :disabled="busy">Создать промокод</button>
    </form>

    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <button v-else-if="failed" class="adm-btn" type="button" @click="load">Повторить загрузку</button>
    <form v-for="row in rows" :key="row.type + row.id" class="adm-card glass adm-grid" @submit.prevent="update(row)">
      <b class="full">{{ row.name }} · {{ row.type === 'percent' ? '%' : '₽' }}</b>
      <small class="adm-note full">В вебаппе оплачено {{ row.web_paid }} · в ожидании {{ row.web_pending }}</small>
      <label class="adm-f">Размер скидки<input v-model.number="row.discount" type="number" min="1" :max="row.type === 'percent' ? 100 : 1000000" required></label>
      <label class="adm-f">Осталось использований<input v-model.number="row.uses" type="number" min="0" max="1000000" required></label>
      <button class="adm-btn soft" type="submit" :disabled="busy">Сохранить</button>
    </form>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section } from './shared'

const blank = () => ({ name: '', type: 'percent', discount: null, uses: 1 })

export default {
  name: 'AdminPromos',
  mixins: [section],
  data: () => ({ form: blank(), rows: [] }),
  mounted() { this.load() },
  methods: {
    load() { return this.fetch(async () => { this.rows = (await adminApi.list('promos')).data }) },
    create() {
      return this.run(async () => {
        await adminApi.create('promos', this.form)
        this.form = blank()
        await this.load()
      }, 'Промокод создан')
    },
    update(row) {
      return this.run(async () => {
        await adminApi.updatePromo(row.type, row.id, { discount: row.discount, uses: row.uses })
        await this.load()
      }, 'Промокод сохранён')
    },
  },
}
</script>
