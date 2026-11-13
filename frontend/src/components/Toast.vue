<template>
  <div :class="['island', shown ? 'show' : '']" role="status" aria-live="polite" @click="shown = false">
    <Sticker v-if="state && state.sticker" :name="state.sticker" :size="28" />
    <svg v-else class="ok" aria-hidden="true"><use href="#i-check" /></svg>
    <span class="txt">{{ state ? state.text : '' }}</span>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from './Sticker.vue'

// One notification at a time: it slides down from the top edge, fits the screen width and wraps long text.
export default {
  name: 'Toast',
  components: { Sticker },
  data: () => ({ shown: false, timer: 0, state: null }),
  computed: { app() { return useApp() } },
  watch: {
    'app.toastState'(s) {
      if (!s) return
      clearTimeout(this.timer)
      const show = () => {
        this.state = s
        this.shown = true
        // longer messages stay longer
        this.timer = setTimeout(() => { this.shown = false }, Math.min(5000, 2200 + s.text.length * 35))
      }
      // a message that replaces another one first lets the old one leave
      if (this.shown) { this.shown = false; this.timer = setTimeout(show, 180) } else show()
    },
  },
}
</script>
