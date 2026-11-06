<template>
  <span class="odo" :aria-label="text">
    <template v-for="ch in chars" :key="ch.key">
      <span v-if="ch.digit" class="odo-d"><span class="odo-col" :style="colStyle(ch)"><span v-for="d in 10" :key="d">{{ d - 1 }}</span></span></span>
      <span v-else v-html="ch.value === ' ' ? '&nbsp;' : ch.value" />
    </template>
  </span>
</template>

<script>
import { fmt, reduceMotion } from '../services/format'

// Rolling digits. Each column is a strip 0-9 that slides to the target digit.
export default {
  name: 'Odometer',
  props: { value: { type: Number, required: true }, animate: { type: Boolean, default: true } },
  data() { return { shown: this.value, ready: false, fresh: [] } },
  computed: {
    text() { return fmt(this.shown) },
    chars() {
      // keys count from the right, so a column keeps its identity when the number gains a digit
      const arr = [...this.text]
      return arr.map((value, i) => {
        const r = arr.length - 1 - i
        return /\d/.test(value) ? { digit: true, value, key: 'd' + r, r } : { digit: false, value, key: 's' + r, r }
      })
    },
  },
  watch: {
    value(n) {
      const before = fmt(this.shown).length
      this.shown = n
      const after = fmt(n).length
      if (after > before && this.ready && this.animate && !reduceMotion()) {
        // columns that did not exist yet start at 0 and roll up to their digit
        this.fresh = Array.from({ length: after - before }, (_, k) => before + k)
        requestAnimationFrame(() => requestAnimationFrame(() => { this.fresh = [] }))
      }
    },
  },
  mounted() { requestAnimationFrame(() => { this.ready = true }) },
  methods: {
    colStyle(ch) {
      const animate = this.ready && this.animate && !reduceMotion()
      const fresh = this.fresh.includes(ch.r)
      return {
        transform: `translateY(${fresh ? 0 : -ch.value * 1.15}em)`,
        transition: animate && !fresh ? '' : 'none',
        transitionDelay: animate ? `${ch.r * 35}ms` : '0ms',
      }
    },
  },
}
</script>
