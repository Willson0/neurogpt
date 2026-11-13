<template>
  <h3 id="sheetTitle">{{ info.name }}</h3>
  <p class="lead">{{ info.hint }}. Спишется {{ tok(info.cost) }}.</p>
  <label class="pill-btn psetfile">
    <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" @change="pick">
    <svg class="ic-s" aria-hidden="true"><use :href="file ? '#i-check' : '#i-clip'" /></svg><span class="fname">{{ file ? file.name : 'Выбрать фото' }}</span>
  </label>

  <div v-if="info.input === 'choice'" style="display:grid;gap:var(--space-xs)">
    <span class="ctl-label">Стиль</span>
    <div class="filters" style="flex-wrap:wrap">
      <button v-for="o in info.options" :key="o.id" type="button" class="chip" :aria-pressed="String(option === o.id)" @click="option = o.id">{{ o.name }}</button>
    </div>
  </div>
  <div v-else-if="info.input && info.input !== 'none'" class="prompt glass">
    <label for="toolText" class="visually-hidden">{{ required ? 'Что сделать' : 'Пожелания' }}</label>
    <textarea id="toolText" v-model="text" rows="2" maxlength="500" :placeholder="info.placeholder || (required ? 'Опишите, что нужно сделать' : 'Пожелания, если есть')" />
  </div>

  <button type="button" class="bigbtn" :disabled="app.busy" @click="go"><span class="lbl">Запустить</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import { tok, checkImage } from '../services/format'

export default {
  name: 'ToolSheet',
  props: { tool: { type: String, required: true } },
  data: () => ({ file: null, text: '', option: '' }),
  computed: {
    app() { return useApp() },
    info() { return this.app.tools.find((t) => t.id === this.tool) || { name: '', hint: '', cost: 0, input: 'none', options: [] } },
    required() { return this.info.input === 'text_required' },
  },
  created() { this.option = this.info.options?.[0]?.id || '' },
  methods: {
    tok,
    pick(e) {
      const f = e.target.files[0] || null
      e.target.value = ''
      const err = f && checkImage(f, this.app.limits.upload_mb)
      if (err) return this.app.toast(err, 'cry')
      this.file = f
    },
    async go() {
      if (!this.file) return this.app.toast('Сначала выберите фото')
      if (this.required && !this.text.trim()) return this.app.toast('Напишите, что нужно сделать')
      const extra = this.info.input === 'choice' ? { option: this.option } : { prompt: this.text.trim() }
      this.app.closeSheet()
      await this.app.startTool(this.tool, this.file, null, extra)
    },
  },
}
</script>
