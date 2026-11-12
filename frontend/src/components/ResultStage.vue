<template>
  <div :class="['stage', done ? 'done' : '']" :style="{ '--ar': ar, ...(selected ? { outline: '2px solid var(--color-accent)', outlineOffset: '2px' } : {}) }">
    <canvas ref="cv" role="img" aria-label="Результат генерации" />
    <video v-if="isVideo && done" :src="item.result_url" class="vid" autoplay loop muted playsinline controls style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
    <audio v-if="isSong && done" :src="item.result_url" class="aud" controls preload="metadata" style="position:absolute;left:var(--space-sm);right:var(--space-sm);bottom:var(--space-sm);width:calc(100% - 2 * var(--space-sm))" />
    <span v-if="!done && !failed" class="scan" />
    <span v-if="!done && !failed" class="step">{{ label }}</span>
  </div>
</template>

<script>
import { reduceMotion } from '../services/format'

// A calm aurora that resolves into the real result: the waiting state and the reveal in one canvas.
export default {
  name: 'ResultStage',
  props: { item: { type: Object, required: true }, ar: { type: Number, default: 1 }, selected: Boolean },
  emits: ['revealed'],
  data: () => ({ done: false, raf: 0, img: null, t0: 0, revealStart: 0, now: Date.now() }),
  computed: {
    failed() { return ['failed', 'cancelled'].includes(this.item.status) },
    isSong() { return /\.(mp3|wav|ogg|m4a|aac|flac)(\?|$)/i.test(this.item.result_url || '') || this.item.kind === 'song' },
    isVideo() { return /\.(mp4|mov|webm)(\?|$)/i.test(this.item.result_url || '') },
    label() {
      if (this.item.status === 'queued') return 'В очереди'
      const s = Math.max(1, Math.round((this.now - this.t0) / 1000))
      return `Генерируем, ${s} с`
    },
  },
  watch: {
    'item.result_url': { immediate: true, handler(url) { if (url) this.load(url) } },
  },
  mounted() {
    this.t0 = Date.now()
    this.resize()
    this.timer = setInterval(() => { this.now = Date.now() }, 1000)
    this.raf = requestAnimationFrame(this.frame)
  },
  beforeUnmount() { cancelAnimationFrame(this.raf); clearInterval(this.timer) },
  methods: {
    resize() {
      const cv = this.$refs.cv
      const r = this.$el.getBoundingClientRect()
      const dpr = Math.min(2, window.devicePixelRatio || 1)
      cv.width = Math.max(1, Math.round(r.width * dpr)); cv.height = Math.max(1, Math.round(r.height * dpr))
      this.dpr = dpr
    },
    load(url) {
      if (this.isVideo) { this.img = 'video'; return }
      if (this.isSong) { this.img = 'audio'; return }
      const img = new Image()
      img.onload = () => { this.img = img }
      img.onerror = () => { this.img = 'broken' }
      img.src = url
    },
    finish() {
      this.done = true
      this.$emit('revealed')
    },
    frame(now) {
      const cv = this.$refs.cv
      if (!cv) return
      const ctx = cv.getContext('2d')
      const W = cv.width, H = cv.height
      this.drawAurora(ctx, W, H, now)
      ctx.imageSmoothingEnabled = true

      const ready = this.img && this.img !== 'broken' && this.img !== 'video' && this.img !== 'audio' && this.item.status === 'done'
      if (this.failed || this.img === 'broken') { ctx.fillStyle = 'rgba(0,0,0,.25)'; ctx.fillRect(0, 0, W, H); return }
      if (this.img === 'video') { this.finish(); return }
      if (this.img === 'audio' && this.item.status === 'done') { if (!this.done) this.finish(); this.raf = requestAnimationFrame(this.frame); return }
      if (!ready) {
        this.raf = requestAnimationFrame(this.frame)
        return
      }
      if (reduceMotion()) { this.drawCover(ctx, this.img, W, H); this.finish(); return }
      if (!this.revealStart) this.revealStart = now
      const t = Math.min(1, (now - this.revealStart) / 1700)
      const e = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2
      const block = Math.max(1, Math.round(40 * Math.pow(1 - e, 2.2)))
      this.small ??= document.createElement('canvas')
      const small = this.small
      small.width = Math.max(1, Math.ceil(W / block)); small.height = Math.max(1, Math.ceil(H / block))
      this.drawCover(small.getContext('2d'), this.img, small.width, small.height)
      ctx.filter = `blur(${(1 - e) * 10 * this.dpr}px)`
      ctx.imageSmoothingEnabled = block < 3
      ctx.drawImage(small, 0, 0, W, H)
      ctx.filter = 'none'
      this.fade ??= document.createElement('canvas')
      this.fade.width = W; this.fade.height = H
      this.drawAurora(this.fade.getContext('2d'), W, H, now)
      ctx.globalAlpha = Math.pow(1 - e, 1.6) * 0.9
      ctx.drawImage(this.fade, 0, 0, W, H); ctx.globalAlpha = 1
      if (t < 1) this.raf = requestAnimationFrame(this.frame)
      else this.finish()
    },
    /** Slow drifting colour clouds: calm to look at, no per-frame flicker. */
    drawAurora(ctx, W, H, now) {
      if (!this.hues) {
        const cs = getComputedStyle(document.documentElement)
        this.hues = ['--plasma-1', '--plasma-2', '--plasma-3'].map((k) => cs.getPropertyValue(k).trim() || '#e8683a')
        this.base = cs.getPropertyValue('--color-surface').trim() || '#161212'
      }
      const t = reduceMotion() ? 0 : (now - (this.t0 || 0)) / 1000
      ctx.globalCompositeOperation = 'source-over'
      ctx.fillStyle = this.base
      ctx.fillRect(0, 0, W, H)
      const R = Math.max(W, H) * 0.62
      this.hues.forEach((c, i) => {
        const x = W * (0.5 + 0.32 * Math.sin(t * 0.35 + i * 2.1))
        const y = H * (0.5 + 0.32 * Math.cos(t * 0.28 + i * 1.7))
        const g = ctx.createRadialGradient(x, y, 0, x, y, R)
        g.addColorStop(0, c)
        g.addColorStop(1, 'transparent')
        ctx.globalAlpha = 0.55
        ctx.fillStyle = g
        ctx.fillRect(0, 0, W, H)
      })
      ctx.globalAlpha = 1
      // a soft light band sweeping across, one pass every ~3.5 s
      const sw = ((t % 3.5) / 3.5) * 1.6 - 0.3
      const bg = ctx.createLinearGradient(W * (sw - 0.25), 0, W * (sw + 0.25), H * 0.4)
      bg.addColorStop(0, 'rgba(255,255,255,0)'); bg.addColorStop(0.5, 'rgba(255,255,255,0.10)'); bg.addColorStop(1, 'rgba(255,255,255,0)')
      ctx.fillStyle = bg
      ctx.fillRect(0, 0, W, H)
    },
    drawCover(ctx, img, W, H) {
      const s = Math.max(W / img.width, H / img.height)
      const w = img.width * s, h = img.height * s
      ctx.imageSmoothingEnabled = true
      ctx.drawImage(img, (W - w) / 2, (H - h) / 2, w, h)
    },
  },
}
</script>
