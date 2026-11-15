<template>
  <div class="promo">
    <div v-if="app.promo" class="banner glass promo-on">
      <Sticker name="gift" :size="32" />
      <span class="promo-t"><b>{{ app.promo.code }} · −{{ app.promo.discount }}%</b><small v-if="target && !off">не действует на этот товар</small><small v-else-if="target">{{ was }} → {{ now }}</small></span>
      <button type="button" class="pill-btn" aria-label="Убрать промокод" @click="app.clearPromo()"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
    </div>
    <button v-else-if="!open" type="button" class="promo-ask" @click="show">Есть промокод?</button>
    <form v-else class="promo-form glass" @submit.prevent="apply">
      <label for="promoCode" class="visually-hidden">Промокод</label>
      <input id="promoCode" ref="input" v-model.trim="code" maxlength="64" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Промокод" enterkeyhint="done">
      <button type="submit" class="pill-btn hot" :disabled="!code || app.busy">Применить</button>
    </form>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from './Sticker.vue'

// The promo code field under the payment method: "Есть промокод?" opens the input, the server checks the code.
export default {
  name: 'PromoField',
  components: { Sticker },
  data: () => ({ open: false, code: '' }),
  computed: {
    app() { return useApp() },
    target() { return this.app.payTarget },
    off() { return this.app.promoOff(this.target) },
    was() { return `${Number(this.target?.price || 0).toLocaleString('ru-RU')} ₽` },
    now() { return `${this.app.discounted(this.target?.price, 0, this.target).rub.toLocaleString('ru-RU')} ₽` },
  },
  methods: {
    show() { this.open = true; this.$nextTick(() => this.$refs.input?.focus()) },
    async apply() {
      if (await this.app.applyPromo(this.code)) { this.open = false; this.code = '' }
    },
  },
}
</script>

<style>
.promo-ask { justify-self: start; border: 0; background: none; padding: var(--space-2xs) 0; color: var(--color-accent); font-size: var(--text-2); font-weight: 600; }
.promo-form { display: flex; align-items: center; gap: var(--space-xs); padding: var(--space-2xs) var(--space-2xs) var(--space-2xs) var(--space-md); border-radius: var(--radius-pill); }
.promo-form input { flex: 1; min-width: 0; border: 0; background: transparent; color: var(--color-ink); font: inherit; font-size: var(--text-2); text-transform: uppercase; letter-spacing: 0.04em; min-height: 40px; outline: none; }
.promo-form input::placeholder { text-transform: none; letter-spacing: 0; color: var(--color-muted); }
.promo-on .promo-t { display: grid; flex: 1; min-width: 0; }
.promo-on small { color: var(--color-muted); font-size: var(--text-cap); }
.promo { display: grid; }
</style>
