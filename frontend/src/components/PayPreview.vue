<template>
  <div class="boot pay-preview">
    <template v-if="item">
      <Sticker :name="item.kind === 'tokens' ? 'coin' : item.plan === 'pro' ? 'crown' : 'gem'" :size="96" glow glow-color="--gold-hi" />
      <h3>{{ item.name }}</h3>
      <p>{{ line }}</p>
      <div class="gets"><span class="glass"><b>{{ rub }}</b></span><span v-if="item.methods.stars" class="glass">{{ stars }}</span></div>
      <a v-if="item.available && item.telegram_link" class="pill-btn hot" style="text-decoration:none" :href="item.telegram_link">Оплатить в Telegram</a>
      <p v-else-if="!item.available">Этот товар сейчас нельзя купить.</p>
      <p v-else>Откройте бота в Telegram, чтобы оплатить.</p>
    </template>
    <template v-else-if="failed">
      <Sticker name="cry" :size="96" />
      <h3>Товар не найден</h3>
      <p>Проверьте ссылку на оплату.</p>
    </template>
    <Sticker v-else name="art" :size="96" />
  </div>
</template>

<script>
import { api } from '../services/api'
import { fmt, plural } from '../services/format'
import Sticker from './Sticker.vue'

// The payment link (/pay/<shop id>) opened in a plain browser: there is no Telegram sign-in here, so the page shows
// what is sold and sends the person to the same payment screen inside Telegram.
export default {
  name: 'PayPreview',
  components: { Sticker },
  props: { itemId: { type: Number, required: true } },
  data: () => ({ item: null, failed: false }),
  computed: {
    rub() { return `${Number(this.item.price).toLocaleString('ru-RU')} ₽` },
    stars() { return `${Number(this.item.stars).toLocaleString('ru-RU')} ⭐` },
    line() {
      const i = this.item
      if (i.kind === 'tokens') return `${fmt(i.tokens)} ${plural(i.tokens, 'токен', 'токена', 'токенов')} в NeuroGPT`
      return `Подписка NeuroGPT на ${i.days} ${plural(i.days, 'день', 'дня', 'дней')}`
    },
  },
  async mounted() {
    try { this.item = (await api.payItem(this.itemId)).data } catch { this.failed = true }
  },
}
</script>
