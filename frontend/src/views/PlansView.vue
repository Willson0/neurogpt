<template>
  <div class="view" data-view="plans">
    <h1>Подписка</h1>
    <div class="plan-current glass">
      <b>{{ app.planName }}</b>
      <span v-if="expiresAt">Действует до {{ expiresAt }}</span>
    </div>

    <div v-if="offer" class="holo-wrap">
      <div ref="holo" class="holo" :style="tilt" @pointermove="onMove" @pointerleave="tilt = {}">
        <div :key="offer.id" class="swap-in">
          <div class="top"><span class="name">{{ offer.name }}</span><Sticker name="crown" :size="64" glow glow-color="--gold-hi" /></div>
        </div>
        <div class="bottom-l">
          <span class="price-l">{{ price }}<small>за {{ offer.days }} {{ daysLabel(offer.days) }}</small></span>
          <span v-if="offer.tag" class="line">{{ offer.tag }}</span>
        </div>
      </div>
    </div>

    <label v-if="app.subscriptionOffers.length > 1" class="plan-choice glass">
      Срок и тариф
      <select :value="offer?.id" @change="chooseOffer($event.target.value)">
        <option v-for="item in app.subscriptionOffers" :key="item.id" :value="item.id">{{ item.name }} · {{ item.days }} {{ daysLabel(item.days) }} · {{ app.priceLabel(item.price, item.stars) }}</option>
      </select>
    </label>
    <p v-if="offer?.description" class="plan-description glass">{{ offer.description }}</p>
    <p v-if="offer?.daily_tokens" class="plan-description glass">{{ offer.daily_tokens }} токенов в день по условиям бота</p>
    <p v-if="!offer" class="plan-description glass">Сейчас нет доступных подписок.</p>
    <p v-else-if="app.plan !== 'free' && offer.plan !== app.plan" class="plan-description glass">Новая подписка начнётся после оплаты. Оставшиеся дни сохранятся.</p>
    <PayMethod v-if="offer" />
    <PromoField v-if="offer" kind="plan" :offer="offer" />
    <label v-if="canRenew" class="plan-description glass">
      <input type="checkbox" :checked="app.autoRenewOffer === offer.id" @change="app.autoRenewOffer = $event.target.checked ? offer.id : null">
      Продлевать картой до {{ app.priceLabel(offer.price, offer.stars) }} на {{ offer.days }} {{ daysLabel(offer.days) }} после окончания подписки. При нехватке средств возможна оплата меньшего срока по цене магазина.
    </label>
    <div v-if="app.user?.auto_renewal" class="plan-current glass">
      <span>Автопродление подключено</span>
      <button class="pill-btn" type="button" :disabled="stopping" @click="stopRenewal">Отключить</button>
    </div>

    <div v-if="app.payment && app.payment.purpose === 'plan'" class="banner glass">
      <Sticker name="hourglass" :size="32" />
      <span>Ждём подтверждения оплаты</span>
      <button type="button" class="pill-btn" @click="app.watchPayment()">Проверить</button>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { plural, reduceMotion } from '../services/format'
import { tg } from '../services/telegram'
import Sticker from '../components/Sticker.vue'
import { api } from '../services/api'
import PromoField from '../components/PromoField.vue'
import PayMethod from '../components/PayMethod.vue'

export default {
  name: 'PlansView',
  components: { Sticker, PayMethod, PromoField },
  data: () => ({ tilt: {}, stopping: false }),
  computed: {
    app() { return useApp() },
    offer() { return this.app.subscriptionOffer },
    price() { return this.app.checkoutPrice('plan', this.offer) },
    canRenew() { return this.app.payments.auto_renewal && this.app.method === 'card' && this.offer?.days === this.app.payments.auto_renewal_days },
    expiresAt() {
      const date = this.app.user?.plan_expires_at
      return date ? new Date(date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
    },
  },
  methods: {
    async stopRenewal() {
      this.stopping = true
      try {
        const result = await api.stopRenewal()
        this.app.setUser(result.user)
        this.app.autoRenewOffer = null
        this.app.toast('Автопродление отключено')
      } catch (error) {
        this.app.handleActionError(error)
      } finally {
        this.stopping = false
      }
    },
    daysLabel(days) { return plural(days, 'день', 'дня', 'дней') },
    chooseOffer(id) { this.app.subscriptionOfferId = Number(id); tg.haptic('selection') },
    onMove(e) {
      if (reduceMotion()) return
      const r = this.$refs.holo.getBoundingClientRect()
      const px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height
      this.tilt = { '--ry': `${(px - 0.5) * 18}deg`, '--rx': `${(0.5 - py) * 14}deg`, '--mx': `${px * 100}%`, '--my': `${py * 100}%` }
    },
  },
}
</script>

<style scoped>
.swap-in { animation: swap 320ms var(--ease-out); }
.plan-current, .plan-choice, .plan-description { margin: 0; padding: var(--space-sm) var(--space-md); border-radius: var(--radius-card); }
.plan-current, .plan-choice { display: grid; gap: var(--space-xs); }
.plan-current span { color: var(--color-muted); }
.plan-description { white-space: pre-wrap; overflow-wrap: anywhere; }
.plan-choice select { min-width: 0; width: 100%; color: var(--color-ink); background: var(--color-surface-2); border: 0; border-radius: var(--radius-ctl); padding: 10px; font: inherit; }
</style>
