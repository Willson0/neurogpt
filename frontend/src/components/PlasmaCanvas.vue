<template>
  <canvas ref="cv" class="plasma" :style="{ opacity: dim ? 0.45 : '' }" aria-hidden="true" />
</template>

<script>
import { useApp } from '../stores/app'
import { reduceMotion, tokenColor } from '../services/format'

// The app's "state light": brightens while typing and races while generating.
export default {
  name: 'PlasmaCanvas',
  // still: one frame and no animation. The admin panel is a page of glass cards, and each of them re-blurs
  // the moving canvas under it on every frame: with the glow standing still the panel scrolls smoothly.
  props: { dim: Boolean, still: Boolean },
  data: () => ({ energy: 0.35, t: 0, raf: 0, last: 0, cols: null }),
  computed: { app() { return useApp() } },
  mounted() {
    this.start()
    document.addEventListener('visibilitychange', this.start)
    // colors are read once and again only when the theme changes: reading styles every frame is costly
    this.recolor = () => { this.cols = null; this.start() }
    document.addEventListener('ng-theme', this.recolor)
    this.scheme = new MutationObserver(this.recolor)
    this.scheme.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
  },
  beforeUnmount() {
    cancelAnimationFrame(this.raf)
    this.scheme.disconnect()
    document.removeEventListener('ng-theme', this.recolor)
    document.removeEventListener('visibilitychange', this.start)
  },
  watch: {
    'app.plasma.target'() { this.start() },
    still() { this.start() },
  },
  methods: {
    start() {
      if (!this.raf && !document.hidden) this.raf = requestAnimationFrame(this.frame)
    },
    frame(now = 0) {
      const p = this.app.plasma
      const cv = this.$refs.cv
      if (!cv) return
      this.raf = 0
      // 30 frames a second is plenty for a slow glow and halves the repaint work of everything above it
      const dt = now - this.last
      if (dt < 30 && this.cols) { this.raf = requestAnimationFrame(this.frame); return }
      const k = this.last ? Math.min(4, dt / 16.7) : 1
      this.last = now
      const x = cv.getContext('2d')
      const w = 120, h = 130
      if (cv.width !== w) { cv.width = w; cv.height = h }
      this.energy += (p.target - this.energy) * Math.min(1, 0.06 * k)
      this.t += 0.006 * k * p.speed * (0.6 + this.energy)
      x.clearRect(0, 0, w, h)
      const cols = (this.cols ||= ['--plasma-1', '--plasma-2', '--plasma-3'].map(tokenColor))
      x.globalCompositeOperation = 'lighter'
      for (let i = 0; i < 3; i++) {
        const cx = w * (0.5 + Math.sin(this.t * (1 + i * 0.37) + i * 2) * 0.32)
        const cy = h * (0.32 + Math.cos(this.t * (0.8 + i * 0.29) + i) * 0.18)
        const rad = w * (0.32 + this.energy * 0.22 + i * 0.04)
        const g = x.createRadialGradient(cx, cy, 0, cx, cy, rad)
        g.addColorStop(0, cols[i]); g.addColorStop(1, 'transparent')
        x.globalAlpha = 0.28 + this.energy * 0.45; x.fillStyle = g; x.fillRect(0, 0, w, h)
      }
      x.globalCompositeOperation = 'destination-out'; x.globalAlpha = 1
      const m = x.createLinearGradient(0, h * 0.45, 0, h)
      m.addColorStop(0, 'rgba(0,0,0,0)'); m.addColorStop(1, 'rgba(0,0,0,1)')
      x.fillStyle = m; x.fillRect(0, 0, w, h)
      x.globalCompositeOperation = 'source-over'
      // the target relaxes back to idle
      if (p.target > 0.36 && p.target < 1.2) p.target = Math.max(0.35, p.target - 0.004 * k)
      if (!this.still && !reduceMotion() && !document.hidden) this.raf = requestAnimationFrame(this.frame)
    },
  },
}
</script>
