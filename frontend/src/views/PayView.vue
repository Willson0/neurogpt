<template>
  <div class="view" data-view="pay">
    <div v-if="!item" class="spinner-row">Загружаем…</div>
    <template v-else>
      <div class="calc">
        <div class="tgs-wrap"><TgsPlayer v-if="item.kind === 'tokens'" name="token" :size="120" /><Sticker v-else :name="item.plan === 'pro' ? 'crown' : 'gem'" :size="96" glow glow-color="--gold-hi" /></div>
        <div class="amount num">{{ item.name }}<small>{{ line }}</small></div>
        <div class="gets">
          <span v-if="price.off" class="glass"><s>{{ app.priceLabel(item.price, item.stars) }}</s></span>
          <span class="glass"><b>{{ app.priceLabel(item.price, item.stars, item) }}</b></span>
          <span v-if="price.off" class="badge hot" style="margin:0;align-self:center">−{{ price.off }}%</span>
          <span v-else-if="item.badge" class="badge hot" style="margin:0;align-self:center">{{ item.badge }}</span>
        </div>
      </div>
      <div v-if="!item.available" class="banner glass"><Sticker name="sleep" :size="32" /><span>Этот товар сейчас нельзя купить</span></div>
      <template v-else>
        <div class="group" style="display:grid;gap:var(--space-sm)">
          <div class="sec-head"><h2>Способ оплаты</h2></div>
          <PayMethod />
          <PromoField />
        </div>
        <div v-if="app.payment && app.payment.itemId === item.item_id" class="banner glass">
          <Sticker name="hourglass" :size="32" />
          <span>Ждём подтверждения оплаты</span>
          <button type="button" class="pill-btn" @click="app.watchPayment()">Проверить</button>
        </div>
      </template>
    </template>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { fmt, plural } from '../services/format'
import TgsPlayer from '../components/TgsPlayer.vue'
import Sticker from '../components/Sticker.vue'
import PayMethod from '../components/PayMethod.vue'
import PromoField from '../components/PromoField.vue'

// One shop item with its payment methods and the promo code: what a payment link (/pay/<shop id>) opens.
export default {
  name: 'PayView',
  components: { TgsPlayer, Sticker, PayMethod, PromoField },
  computed: {
    app() { return useApp() },
    item() { return this.app.payItem },
    price() { return this.app.discounted(this.item.price, this.item.stars, this.item) },
    line() {
      const i = this.item
      if (i.kind === 'tokens') return `${fmt(i.tokens)} ${plural(i.tokens, 'токен', 'токена', 'токенов')}, не сгорают`
      return `Подписка на ${i.days} ${plural(i.days, 'день', 'дня', 'дней')}${i.tokens ? `, ${fmt(i.tokens)} токенов` : ''}`
    },
  },
}
</script>
