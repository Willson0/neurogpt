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
  computed: {
    app() { return useApp() },
    items() {
      const t = (id) => this.app.tools.find((x) => x.id === id)
      return [
        { title: 'Улучшить качество', icon: 'upscale', price: tok(t('upscale')?.cost ?? 4), tool: 'upscale' },
        { title: 'Похожие варианты', icon: 'redo', price: tok(this.app.model.price), again: true },
        { title: 'Оживить в видео', icon: 'video', price: tok(t('animate')?.cost ?? 40), tool: 'animate' },
      ]
    },
  },
  methods: {
    pick(it) {
      this.app.closeSheet()
      if (it.again) { this.app.stack = ['home']; this.app.tab = 'home'; return this.app.start() }
      setTimeout(() => this.app.openSheet('tool', { tool: it.tool }), 380)
    },
  },
}
</script>
