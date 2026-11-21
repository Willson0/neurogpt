<template>
  <form class="promo-field glass" @submit.prevent="apply">
    <label :for="'promo-' + kind">Промокод</label>
    <div class="promo-controls">
      <input :id="'promo-' + kind" v-model.trim="code" maxlength="64" autocomplete="off" autocapitalize="characters" @input="reset">
      <button v-if="quote" type="button" class="pill-btn" @click="clear">Убрать</button>
      <button v-else type="submit" class="pill-btn" :disabled="!code || busy">Применить</button>
    </div>
    <small v-if="error" role="alert">{{ error }}</small>
    <small v-else-if="quote">Цена со скидкой · {{ app.checkoutPrice(kind, offer) }}</small>
  </form>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../services/api'
import { useApp } from '../stores/app'

const props = defineProps({ kind: { type: String, required: true }, offer: { type: Object, required: true } })
const app = useApp()
const quote = computed(() => app.appliedPromo(props.kind, props.offer))
const code = ref(quote.value?.code || '')
const busy = ref(false)
const error = ref('')
watch(() => props.offer.id, () => { code.value = quote.value?.code || ''; error.value = '' })

function reset() {
  app.promo[props.kind] = null
  error.value = ''
}

function clear() {
  code.value = ''
  reset()
}

async function apply() {
  if (!code.value || busy.value) return
  const { id, price } = props.offer
  const entered = code.value
  busy.value = true
  error.value = ''
  try {
    const result = await api.promoQuote(props.kind, props.offer, entered)
    if (props.offer.id === id && code.value === entered) {
      app.promo[props.kind] = { ...result, code: entered, offerId: id, originalPrice: price }
    }
  } catch (e) {
    if (props.offer.id === id && code.value === entered) error.value = e.body?.errors?.promo?.[0] || 'Не удалось проверить промокод'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.promo-field { display: grid; gap: 8px; padding: var(--space-sm) var(--space-md); border-radius: var(--radius-card); }
.promo-controls { display: flex; gap: 8px; }
input { flex: 1; min-width: 0; padding: 8px; border-radius: var(--radius-ctl); border: 1px solid var(--color-surface-2); background: var(--color-surface-2); color: var(--color-ink); }
</style>
