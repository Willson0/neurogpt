<template>
  <div class="adm-card glass">
    <div class="adm-bar"><h3 class="grow">{{ title }}</h3><span v-if="total !== null" class="adm-note">{{ num(total) }}</span></div>
    <p v-if="note" class="adm-note">{{ note }}</p>
    <ol v-if="shown.length" class="adm-hbars">
      <li v-for="(r, i) in shown" :key="i">
        <span class="lbl"><b>{{ r.label }}</b><small v-if="r.sub">{{ r.sub }}</small></span>
        <span class="val">{{ num(r.n) }}<small v-if="r.extra">{{ r.extra }}</small></span>
        <i :style="{ width: Math.max(2, r.n / max * 100) + '%' }" />
      </li>
    </ol>
    <p v-else class="adm-note">{{ empty }}</p>
    <button v-if="rows.length > limit" type="button" class="adm-btn soft sm" style="justify-self:start" @click="all = !all">{{ all ? 'Свернуть' : `Показать все (${rows.length})` }}</button>
  </div>
</template>

<script>
import { num } from './shared'

/** A ranked list with horizontal bars: buttons, screens, models, sets. */
export default {
  name: 'AdminBars',
  props: {
    title: { type: String, required: true },
    rows: { type: Array, default: () => [] }, // [{ label, sub?, n, extra? }]
    total: { type: Number, default: null },
    note: { type: String, default: '' },
    empty: { type: String, default: 'За этот период данных нет.' },
    limit: { type: Number, default: 8 },
  },
  data: () => ({ all: false }),
  computed: {
    shown() { return this.all ? this.rows : this.rows.slice(0, this.limit) },
    max() { return Math.max(1, ...this.rows.map((r) => r.n)) },
  },
  methods: { num },
}
</script>
