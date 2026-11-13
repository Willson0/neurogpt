<template>
  <div class="view" data-view="plans">
    <LensBar :active="app.period" label="Период">
      <button type="button" role="tab" :aria-selected="String(app.period === 'month')" @click="app.period = 'month'">Месяц</button>
      <button type="button" role="tab" :aria-selected="String(app.period === 'year')" @click="app.period = 'year'">Год, −30%</button>
    </LensBar>

    <div class="holo-wrap">
      <div ref="holo" :class="['holo', 't-' + app.tier]" :style="tilt" @pointermove="onMove" @pointerleave="tilt = {}">
        <div :key="app.tier + app.period" class="swap-in">
          <div class="top"><span class="name">{{ info.name }}</span><Sticker :name="meta.sticker" :size="64" :glow="app.tier !== 'free'" glow-color="--gold-hi" :sparkle="app.tier === 'pro'" /></div>
        </div>
        <div class="bottom-l">
          <span v-if="info.month" class="price-l">{{ monthly }}<small>в месяц{{ app.period === 'year' ? ', при оплате за год' : '' }}</small></span>
          <span v-else class="price-l">Бесплатно<small>навсегда</small></span>
          <span class="line">{{ line }}<template v-if="app.plan === app.tier"><br>ваш тариф</template></span>
        </div>
      </div>
    </div>

    <LensBar :active="app.tier" label="Тариф">
      <button v-for="t in tiers" :key="t" type="button" role="tab" :aria-selected="String(app.tier === t)" @click="app.tier = t; tgHaptic()">{{ app.plans[t] ? app.plans[t].name : t }}</button>
    </LensBar>

    <ul class="feat glass">
      <li v-for="(f, k) in meta.feats" :key="app.tier + k" :style="{ '--i': k }"><svg aria-hidden="true"><use href="#i-check" /></svg>{{ f }}</li>
    </ul>

    <PayMethod v-if="app.tier !== 'free'" />

    <div v-if="app.payment && app.payment.purpose === 'plan'" class="banner glass">
      <Sticker name="hourglass" :size="32" />
      <span>Ждём подтверждения оплаты</span>
      <button type="button" class="pill-btn" @click="app.watchPayment()">Проверить</button>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { fmt, reduceMotion } from '../services/format'
import { tg } from '../services/telegram'
import LensBar from '../components/LensBar.vue'
import Sticker from '../components/Sticker.vue'
import PayMethod from '../components/PayMethod.vue'

const META = {
  free: { sticker: 'sparkles', feats: ['Базовые модели картинок и видео', 'Ежедневный бонус токенов', 'Общая очередь'] },
  plus: { sticker: 'gem', feats: ['Токены сразу на баланс, не сгорают', 'Фотореализм, Veo 3.1 Fast и Kling 3.0', 'Токены дешевле, чем в пакетах'] },
  pro: { sticker: 'crown', feats: ['Всё из Plus и больше токенов', 'Veo 3.1 Quality в 1080p', 'Приоритет в очереди'] },
}

export default {
  name: 'PlansView',
  components: { LensBar, Sticker, PayMethod },
  data: () => ({ tilt: {}, tiers: ['free', 'plus', 'pro'] }),
  computed: {
    app() { return useApp() },
    info() { return this.app.plans[this.app.tier] || { name: '', month: 0, year_month: 0, tokens: 0 } },
    meta() { return META[this.app.tier] },
    monthly() {
      const i = this.info, year = this.app.period === 'year'
      return this.app.priceLabel(year ? i.year_month : i.month, year ? Math.round(i.stars_year / 12) : i.stars_month)
    },
    line() { return this.info.tokens ? `${fmt(this.info.tokens)} токенов в месяц` : 'Бонус каждый день' },
  },
  methods: {
    fmt,
    tgHaptic() { tg.haptic('selection') },
    onMove(e) {
      if (reduceMotion()) return
      const r = this.$refs.holo.getBoundingClientRect()
      const px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height
      this.tilt = { '--ry': `${(px - 0.5) * 18}deg`, '--rx': `${(0.5 - py) * 14}deg`, '--mx': `${px * 100}%`, '--my': `${py * 100}%` }
    },
  },
}
</script>

<style>
.swap-in { animation: swap 320ms var(--ease-out); }
</style>
