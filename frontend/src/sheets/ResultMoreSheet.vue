<template>
  <h3 id="sheetTitle">Что дальше</h3>
  <ul class="list glass">
    <li v-for="it in items" :key="it.title">
      <button type="button" class="row" @click="pick(it)">
        <span class="sq"><svg class="ic-s" aria-hidden="true"><use :href="'#i-' + it.icon" /></svg></span>
        <span class="t"><b>{{ it.title }}</b></span>
        <span class="end">{{ it.price }}</span>
      </button>
    </li>
  </ul>
</template>

<script>
import { useApp } from '../stores/app'
import { tok } from '../services/format'

export default {
  name: 'ResultMoreSheet',
  props: { source: { type: Number, default: null } },
  computed: {
    app() { return useApp() },
    items() {
      const t = (id) => this.app.tools.find((x) => x.id === id)
      const tools = ['upscale', 'remove_bg', 'animate'].map(t).filter(Boolean)
        .map((x) => ({ title: x.name, icon: x.icon, price: tok(x.cost), tool: x.id }))
      return [tools[0], { title: 'Похожие варианты', icon: 'redo', price: tok(this.app.model.price), again: true }, ...tools.slice(1)].filter(Boolean)
    },
  },
  methods: {
    pick(it) {
      this.app.closeSheet()
      if (it.again) { this.app.stack = ['home']; this.app.tab = 'home'; return this.app.start() }
      // the picture on screen is the source: no second upload
      if (this.source) return this.app.startTool(it.tool, null, this.source)
      setTimeout(() => this.app.openSheet('tool', { tool: it.tool }), 380)
    },
  },
}
</script>
