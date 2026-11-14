<template>
  <div class="adm-plot" @pointerleave="hover = -1">
    <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" role="img" :aria-label="aria" @pointermove="move">
      <g class="grid">
        <line v-for="t in ticks" :key="t" x1="0" :x2="W" :y1="y(t)" :y2="y(t)" />
      </g>
      <rect v-for="(v, i) in values" :key="i" :x="i * step + gap / 2" :width="Math.max(1, step - gap)" :y="y(v)" :height="Math.max(v > 0 ? 2 : 0, H - y(v))" rx="2"
        :class="['bar', hover === i ? 'on' : '']" />
    </svg>
    <div class="axis-y" aria-hidden="true"><span v-for="t in ticks" :key="t" :style="{ bottom: (t / top * 100) + '%' }">{{ short(t) }}</span></div>
    <div class="axis-x" aria-hidden="true"><span v-for="l in labels" :key="l.i" :style="{ left: ((l.i + 0.5) / values.length * 100) + '%' }">{{ l.text }}</span></div>
    <div v-if="hover >= 0" :class="['tip', hover < values.length * 0.12 ? 'l' : hover > values.length * 0.88 ? 'r' : '']" :style="{ left: ((hover + 0.5) / values.length * 100) + '%' }">
      <b>{{ fmt(values[hover]) }}</b><span>{{ dayLabel(series[hover].day) }}</span>
    </div>
  </div>
</template>

<script>
/** A bar chart by day for the admin dashboard. Hover (or touch) shows the exact value. */
export default {
  name: 'AdminChart',
  props: {
    series: { type: Array, required: true }, // [{ day: 'YYYY-MM-DD', ...metrics }]
    metric: { type: String, required: true },
    label: { type: String, default: '' },
    money: { type: Boolean, default: false },
  },
  data: () => ({ W: 600, H: 180, hover: -1 }),
  computed: {
    values() { return this.series.map((d) => Number(d[this.metric] || 0)) },
    step() { return this.W / Math.max(1, this.values.length) },
    gap() { return Math.min(6, this.step * 0.3) },
    top() {
      const max = Math.max(1, ...this.values)
      const p = 10 ** Math.floor(Math.log10(max))
      const nice = [1, 2, 2.5, 5, 10].find((m) => m * p >= max) * p
      return nice
    },
    ticks() { return [this.top / 2, this.top] },
    labels() {
      const n = this.values.length
      const every = Math.max(1, Math.ceil(n / 7))
      const out = []
      for (let i = 0; i < n; i += every) out.push({ i, text: this.dayShort(this.series[i].day) })
      return out
    },
    total() { return this.values.reduce((a, b) => a + b, 0) },
    aria() { return `${this.label}: всего ${this.fmt(this.total)} за ${this.values.length} дн., максимум за день ${this.fmt(Math.max(0, ...this.values))}` },
  },
  methods: {
    y(v) { return this.H - (v / this.top) * (this.H - 4) },
    move(e) {
      const r = e.currentTarget.getBoundingClientRect()
      const i = Math.floor(((e.clientX - r.left) / r.width) * this.values.length)
      this.hover = i >= 0 && i < this.values.length ? i : -1
    },
    fmt(v) { return Number(v || 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 }) + (this.money ? ' ₽' : '') },
    short(v) { return v >= 1e6 ? +(v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? +(v / 1e3).toFixed(1) + 'k' : String(+v.toFixed(1)) },
    dayShort(d) { return new Date(d + 'T00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '') },
    dayLabel(d) { return new Date(d + 'T00:00').toLocaleDateString('ru-RU', { weekday: 'short', day: 'numeric', month: 'long' }) },
  },
}
</script>
