<template>
  <div class="view" data-view="topup">
    <div class="calc">
      <div class="tgs-wrap" :style="{ transform: `scale(${0.85 + app.packIndex * 0.08})`, transition: 'transform 400ms var(--ease-pop)' }"><TgsPlayer name="token" :size="140" /></div>
      <div class="amount num"><Odometer :value="app.pack.tokens" /> токенов<small>Токены не сгорают</small></div>
      <div class="gets">
        <span class="glass">≈ {{ imgs }} картинок</span>
        <span class="glass">{{ vids }} {{ plural(vids, 'видео', 'видео', 'видео') }}</span>
        <span class="glass">{{ fmt(app.pack.tokens) }} ответов</span>
        <span v-if="app.pack.badge" class="badge hot" style="margin:0;align-self:center">{{ app.pack.badge }}</span>
      </div>
    </div>
    <div class="range">
      <label for="calcRange" class="visually-hidden">Сколько токенов</label>
      <input id="calcRange" type="range" min="0" :max="app.packs.length - 1" step="1" :value="app.packIndex" :style="{ '--fill': fill }" @input="onRange">
      <div class="ticks">
        <button v-for="(p, k) in app.packs" :key="p.tokens" type="button" :aria-pressed="String(k === app.packIndex)" @click="set(k)">{{ fmt(p.tokens) }}</button>
      </div>
    </div>
    <ul class="list glass">
      <li>
        <div class="row">
          <span class="sq hot"><svg class="ic-s" aria-hidden="true"><use href="#i-wallet" /></svg></span>
          <span class="t"><b>Способ оплаты</b></span>
          <span class="end">Карта или СБП</span>
        </div>
      </li>
    </ul>
    <div v-if="app.payment && app.payment.purpose === 'topup'" class="banner glass">
      <Sticker name="hourglass" :size="32" />
      <span>Ждём подтверждения оплаты</span>
      <button type="button" class="pill-btn" @click="app.watchPayment()">Проверить</button>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { fmt, plural } from '../services/format'
import { tg } from '../services/telegram'
import Odometer from '../components/Odometer.vue'
import TgsPlayer from '../components/TgsPlayer.vue'
import Sticker from '../components/Sticker.vue'

export default {
  name: 'TopupView',
  components: { Odometer, TgsPlayer, Sticker },
  computed: {
    app() { return useApp() },
    fill() { return `${(this.app.packIndex / Math.max(1, this.app.packs.length - 1)) * 100}%` },
    imgs() { return Math.floor(this.app.pack.tokens / (this.app.models.image[0]?.price || 8)) },
    vids() { return Math.floor(this.app.pack.tokens / (this.app.models.video.find((m) => m.id === 'clip')?.price || 40)) },
  },
  methods: {
    fmt, plural,
    onRange(e) { this.set(+e.target.value) },
    set(k) { this.app.packIndex = k; tg.haptic('selection') },
  },
}
</script>
