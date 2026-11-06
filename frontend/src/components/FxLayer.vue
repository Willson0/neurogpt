<template>
  <div class="fx" aria-hidden="true">
    <i v-for="p in particles" :key="p.id" :style="p.style" />
  </div>
</template>

<script>
import { bus } from '../services/bus'
import { reduceMotion } from '../services/format'

const KINDS = ['--plasma-1', '--plasma-2', '--plasma-3', '--gold-hi']
const RICH = [...KINDS, '--color-success']

export default {
  name: 'FxLayer',
  data: () => ({ particles: [], seq: 0 }),
  mounted() { this.off = bus.on('burst', this.burst) },
  beforeUnmount() { this.off?.() },
  methods: {
    burst({ x, y, n = 14, spread = 90, rich = false }) {
      if (reduceMotion()) return
      const kinds = rich ? RICH : KINDS
      const batch = []
      for (let i = 0; i < n; i++) {
        const a = Math.random() * Math.PI * 2
        const d = spread * (0.5 + Math.random() * 0.8)
        const sq = Math.random() > 0.5
        batch.push({
          id: ++this.seq,
          style: `left:${x}px;top:${y}px;--c:var(${kinds[i % kinds.length]});--x:${Math.cos(a) * d}px;--y:${Math.sin(a) * d - spread * 0.3}px;--r:${Math.random() * 540 - 270}deg;--w:${sq ? 7 : 5}px;--h:${sq ? 10 : 5}px;--br:${sq ? '2px' : '999px'};--d:${600 + Math.random() * 400}ms`,
        })
      }
      this.particles.push(...batch)
      const ids = new Set(batch.map((b) => b.id))
      setTimeout(() => { this.particles = this.particles.filter((p) => !ids.has(p.id)) }, 1100)
    },
  },
}
</script>
