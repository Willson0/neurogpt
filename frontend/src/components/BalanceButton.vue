<template>
  <button ref="btn" type="button" class="balance glass" aria-label="Баланс, пополнить" @click="app.push('topup')">
    <svg ref="token" class="token" aria-hidden="true"><use href="#i-token" /></svg>
    <Odometer :value="app.balance" class="js-bal" />
    <span class="plus"><svg class="ic-s" aria-hidden="true"><use href="#i-add" /></svg></span>
  </button>
</template>

<script>
import { useApp } from '../stores/app'
import Odometer from './Odometer.vue'
import { replay } from '../services/format'

export default {
  name: 'BalanceButton',
  components: { Odometer },
  computed: { app() { return useApp() } },
  watch: {
    'app.balance'(n, o) { if (o !== undefined) replay(this.$refs.token, 'bump') },
  },
}
</script>
