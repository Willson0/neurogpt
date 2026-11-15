<template>
  <div class="adm-grid wide">
    <label class="adm-f">Код<input v-model.trim="f.code" required maxlength="64" title="Буквы, цифры, _ и -, без пробелов" placeholder="SPRING20" style="text-transform:uppercase"></label>
    <label class="adm-f">Скидка, %<input v-model.number="f.discount" type="number" min="1" max="99" required></label>
    <label class="adm-f">Осталось использований<input v-model.number="f.uses" type="number" min="0" placeholder="без лимита"></label>
    <label class="adm-f">На одного человека<input v-model.number="f.per_user" type="number" min="1" placeholder="без лимита"></label>
    <label class="adm-f">Начало<input v-model="f.starts_at" type="datetime-local"></label>
    <label class="adm-f">Окончание<input v-model="f.expires_at" type="datetime-local"></label>
    <label class="adm-f">Минимальная цена товара, ₽<input v-model.number="f.min_price" type="number" min="1" placeholder="любая"></label>
    <label class="adm-f">Категория<select v-model="f.category"><option value="">Любая</option><option v-for="c in categories" :key="c" :value="c">{{ c === 'token' ? 'Токены (token)' : c === 'sub' ? 'Подписки (sub)' : c }}</option></select></label>
    <label class="adm-check"><input v-model="f.active" type="checkbox">Включён</label>
    <label class="adm-check"><input v-model="f.new_buyers_only" type="checkbox">Только для первой покупки</label>
    <label class="adm-f full">Заметка для себя<input v-model.trim="f.note" maxlength="255" placeholder="Для блогера, рассылка и т. п."></label>
  </div>
  <div v-if="items.length" class="adm-sub">
    <span class="ctl-label">Только на эти товары (ничего не выбрано = на все)</span>
    <div class="filters" style="flex-wrap:wrap">
      <button v-for="i in items" :key="i.id" type="button" class="chip" :aria-pressed="String(f.item_ids.includes(i.id))" @click="toggle(i.id)">{{ i.name }} · {{ i.price }} ₽</button>
    </div>
  </div>
  <p class="adm-note">Код, скидка и остаток использований записываются в таблицу бота promos. Остальные ограничения проверяет приложение. Использование засчитывается, когда платёж оплачен.</p>
</template>

<script>
export default {
  name: 'PromoFields',
  props: { f: { type: Object, required: true }, items: { type: Array, default: () => [] } },
  computed: { categories() { return [...new Set(this.items.map((i) => i.category))] } },
  methods: {
    toggle(id) {
      const i = this.f.item_ids.indexOf(id)
      if (i >= 0) this.f.item_ids.splice(i, 1)
      else this.f.item_ids.push(id)
    },
  },
}
</script>
