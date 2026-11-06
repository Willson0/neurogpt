<template>
  <div ref="bar" :class="['lensbar', 'glass', extra]" :role="role" :aria-label="label">
    <span ref="lens" class="lens" aria-hidden="true" />
    <slot />
  </div>
</template>

<script>
import { reduceMotion } from '../services/format'

// Segmented control with a spring-driven "liquid" lens under the active button.
export default {
  name: 'LensBar',
  props: {
    active: { type: [String, Number], default: '' },
    role: { type: String, default: 'tablist' },
    label: { type: String, default: '' },
    extra: { type: String, default: '' },
  },
  data: () => ({ x: 0, v: 0, target: 0, raf: 0, last: 0 }),
  watch: { active() { this.$nextTick(() => this.place(false)) } },
  mounted() {
    this.place(true)
    this.ro = new ResizeObserver(() => this.place(true))
    this.ro.observe(this.$refs.bar)
  },
  beforeUnmount() { this.ro?.disconnect(); cancelAnimationFrame(this.raf) },
  methods: {
    buttons() { return [...this.$refs.bar.querySelectorAll('button')] },
    activeIndex() {
      const i = this.buttons().findIndex((b) => b.getAttribute('aria-selected') === 'true' || b.getAttribute('aria-current') === 'page')
      return Math.max(0, i)
    },
    place(instant) {
      const b = this.buttons()[this.activeIndex()]
      if (!b || !b.offsetWidth) return
      this.$refs.lens.style.width = b.offsetWidth + 'px'
      this.target = b.offsetLeft
      if (instant || reduceMotion()) { this.x = this.target; this.v = 0; this.render(); return }
      if (!this.raf) { this.last = performance.now(); this.raf = requestAnimationFrame(this.tick) }
    },
    tick(t) {
      const dt = Math.min(0.032, (t - this.last) / 1000)
      this.last = t
      const a = -420 * (this.x - this.target) - 30 * this.v
      this.v += a * dt
      this.x += this.v * dt
      this.render()
      if (Math.abs(this.v) < 2 && Math.abs(this.x - this.target) < 0.3) {
        this.x = this.target; this.v = 0; this.render(); this.raf = 0
        return
      }
      this.raf = requestAnimationFrame(this.tick)
    },
    render() {
      const s = Math.min(Math.abs(this.v) / 1400, 0.3)
      this.$refs.lens.style.transform = `translateX(${this.x}px) scale(${1 + s}, ${1 - s * 0.45})`
    },
  },
}
</script>
