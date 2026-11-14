<template>
  <ul v-if="app.payMethods.length" class="list glass" role="radiogroup" aria-label="Способ оплаты">
    <li v-for="m in list" :key="m.id">
      <button type="button" class="row" role="radio" :aria-checked="String(app.method === m.id)" @click="pick(m.id)">
        <span :class="['sq', m.id === 'stars' ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use :href="m.icon" /></svg></span>
        <span class="t"><b>{{ m.name }}</b><span>{{ m.hint }}</span></span>
        <span v-if="list.length > 1" class="radio-mark"><svg aria-hidden="true"><use href="#i-tick" /></svg></span>
      </button>
    </li>
  </ul>
  <div v-else class="banner glass"><Sticker name="sleep" :size="32" /><span>Оплата временно недоступна</span></div>
</template>

<script>
import { useApp } from '../stores/app'
import { tg } from '../services/telegram'
import Sticker from './Sticker.vue'

const ALL = {
  card: { id: 'card', name: 'Карта или СБП', hint: 'Оплата в рублях через ЮKassa', icon: '#i-wallet' },
  stars: { id: 'stars', name: 'Telegram Stars', hint: 'Звёздами, не выходя из Telegram', icon: '#i-star' },
}

// Payment method choice: card (YooKassa) or Telegram Stars, whichever the server has enabled.
export default {
  name: 'PayMethod',
  components: { Sticker },
  computed: {
    app() { return useApp() },
    list() { return this.app.payMethods.map((id) => ALL[id]) },
  },
  methods: {
    pick(id) { this.app.payMethod = id; tg.haptic('selection') },
  },
}
</script>
