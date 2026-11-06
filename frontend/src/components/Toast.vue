<template>
  <div :class="['island', shown ? 'show' : '']" role="status" aria-live="polite">
    <Sticker v-if="state && state.sticker" :name="state.sticker" :size="30" />
    <svg v-else class="ok" aria-hidden="true"><use href="#i-check" /></svg>
    <span>{{ state ? state.text : '' }}</span>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from './Sticker.vue'

export default {
  name: 'Toast',
  components: { Sticker },
  data: () => ({ shown: false, timer: 0, state: null }),
  computed: { app() { return useApp() } },
  watch: {
    'app.toastState'(s) {
      if (!s) return
      this.state = s
      this.shown = false
      this.$nextTick(() => { void this.$el.offsetWidth; this.shown = true })
      clearTimeout(this.timer)
      this.timer = setTimeout(() => { this.shown = false }, 2300)
    },
  },
}
</script>
