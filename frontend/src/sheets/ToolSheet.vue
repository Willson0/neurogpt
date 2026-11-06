<template>
  <h3 id="sheetTitle">{{ info.name }}</h3>
  <p class="lead">{{ info.hint }}. Загрузите фото, спишется {{ tok(info.cost) }}.</p>
  <label class="pill-btn psetfile">
    <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" @change="pick">
    <svg class="ic-s" aria-hidden="true"><use href="#i-clip" /></svg><span>{{ file ? file.name : 'Выбрать фото' }}</span>
  </label>
  <button type="button" class="bigbtn" :disabled="app.busy" @click="go"><span class="lbl">Запустить</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import { tok } from '../services/format'

export default {
  name: 'ToolSheet',
  props: { tool: { type: String, required: true } },
  data: () => ({ file: null }),
  computed: {
    app() { return useApp() },
    info() { return this.app.tools.find((t) => t.id === this.tool) || { name: '', hint: '', cost: 0 } },
  },
  methods: {
    tok,
    pick(e) { this.file = e.target.files[0] || null },
    async go() {
      if (!this.file) return this.app.toast('Сначала выберите фото')
      if (this.file.size > 10 * 1024 * 1024) return this.app.toast('Фото больше 10 МБ')
      this.app.closeSheet()
      await this.app.startTool(this.tool, this.file)
    },
  },
}
</script>
