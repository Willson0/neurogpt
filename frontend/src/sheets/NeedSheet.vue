<template>
  <div class="hero-stk"><Sticker name="moneybag" :size="96" glow glow-color="--gold" anim="shake" /></div>
  <h3 id="sheetTitle">Не хватает {{ tok(lack) }}</h3>
  <p class="lead">Пополните баланс и запустите генерацию снова</p>
  <ul v-if="pack" class="list glass">
    <li>
      <div class="row">
        <span class="radio-mark" style="border-color:var(--color-cta);background:var(--color-cta);color:var(--color-cta-ink)"><svg aria-hidden="true"><use href="#i-tick" /></svg></span>
        <span class="t"><b>{{ tok(pack.tokens) }}</b><span>≈ {{ Math.floor(pack.tokens / imagePrice) }} картинок</span></span>
        <span class="end num">{{ price }}</span>
      </div>
    </li>
  </ul>
  <PayMethod v-if="pack && app.payMethods.length > 1" />
  <button v-if="pack && app.method" type="button" class="bigbtn shine" :disabled="app.busy" @click="buy"><span class="lbl">Купить за {{ price }}</span></button>
  <button v-if="app.packs.length > 1" type="button" class="ghostbtn" @click="more">Другие пакеты</button>
  <p v-if="!pack" class="lead">Сейчас нет доступных пакетов токенов.</p>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from '../components/Sticker.vue'
import PayMethod from '../components/PayMethod.vue'
import { fmt, tok } from '../services/format'

export default {
  name: 'NeedSheet',
  components: { Sticker, PayMethod },
  props: { lack: { type: Number, default: 1 } },
  computed: {
    app() { return useApp() },
    pack() { return this.app.packs.find((p) => p.tokens >= this.lack) || this.app.packs.at(-1) },
    price() { return this.pack ? this.app.priceLabel(this.pack.price, this.pack.stars) : '' },
    imagePrice() { return this.app.models.image[0]?.price || 8 },
  },
  methods: {
    fmt, tok,
    buy() {
      this.app.packIndex = this.app.packs.indexOf(this.pack)
      this.app.closeSheet()
      this.app.pay('topup', false)
    },
    more() { this.app.closeSheet(); this.app.push('topup') },
  },
}
</script>
