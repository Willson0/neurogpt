<template>
  <span class="odo" :aria-label="text">
    <template v-for="(ch, i) in chars" :key="i + ':' + chars.length">
      <span v-if="ch.digit" class="odo-d"><span class="odo-col" :style="colStyle(ch, i)"><span v-for="d in 10" :key="d">{{ d - 1 }}</span></span></span>
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
  data() { return { shown: this.value, ready: false } },
  computed: {
    text() { return fmt(this.shown) },
    chars() {
      let di = -1
      return [...this.text].map((value) => (/\d/.test(value) ? { digit: true, value, index: ++di } : { digit: false, value }))
    },
  },
  watch: {
    value(n) {
      this.shown = n
    },
  },
  mounted() { requestAnimationFrame(() => { this.ready = true }) },
  methods: {
    colStyle(ch, i) {
      const animate = this.ready && this.animate && !reduceMotion()
      return {
        transform: `translateY(${-ch.value * 1.15}em)`,
        transition: animate ? '' : 'none',
        transitionDelay: animate ? `${i * 35}ms` : '0ms',
      }
    },
  },
}
</script>
