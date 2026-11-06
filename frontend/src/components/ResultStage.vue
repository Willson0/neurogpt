<template>
  <div :class="['stage', done ? 'done' : '']" :style="{ '--ar': ar, ...(selected ? { outline: '2px solid var(--color-accent)', outlineOffset: '2px' } : {}) }">
    <canvas ref="cv" role="img" aria-label="Результат генерации" />
    <video v-if="isVideo && done" :src="item.result_url" class="vid" autoplay loop muted playsinline controls style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
    <span v-if="!done && !failed" class="scan" />
    <span v-if="!done && !failed" class="step">{{ label }}</span>
  </div>
</template>

<script>
import { reduceMotion } from '../services/format'

// Noise that resolves into the real result: the waiting state and the reveal in one canvas.
export default {
  name: 'ResultStage',
  props: { item: { type: Object, required: true }, ar: { type: Number, default: 1 }, selected: Boolean },
  emits: ['revealed'],
  data: () => ({ done: false, raf: 0, img: null, t0: 0, revealStart: 0, now: Date.now() }),
  computed: {
    failed() { return ['failed', 'cancelled'].includes(this.item.status) },
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
      this.noise ??= document.createElement('canvas')
      const noise = this.noise
      if (!noise.width) { noise.width = 96; noise.height = Math.max(1, Math.round(96 * H / W)) }
      const nx = noise.getContext('2d')
      const nd = nx.createImageData(noise.width, noise.height)
      for (let i = 0; i < nd.data.length; i += 4) {
        const v = Math.random() * 255
        nd.data[i] = v; nd.data[i + 1] = v * (0.6 + Math.random() * 0.4); nd.data[i + 2] = v * Math.random(); nd.data[i + 3] = 255
      }
      nx.putImageData(nd, 0, 0)
      ctx.imageSmoothingEnabled = false

      const ready = this.img && this.img !== 'broken' && this.img !== 'video' && this.item.status === 'done'
      if (this.failed || this.img === 'broken') { ctx.fillStyle = 'rgba(0,0,0,.25)'; ctx.fillRect(0, 0, W, H); return }
      if (this.img === 'video') { this.finish(); return }
      if (!ready) {
        ctx.drawImage(noise, 0, 0, W, H)
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
      ctx.globalAlpha = Math.pow(1 - e, 1.6) * 0.95; ctx.imageSmoothingEnabled = false
      ctx.drawImage(noise, 0, 0, W, H); ctx.globalAlpha = 1
      if (t < 1) this.raf = requestAnimationFrame(this.frame)
      else this.finish()
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
