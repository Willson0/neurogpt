<template>
  <h3 id="sheetTitle">Модель</h3>
  <p class="lead">Цена в токенах за один запрос</p>
  <ul class="list glass" role="radiogroup">
    <li v-for="m in list" :key="m.id">
      <button type="button" class="row" role="radio" :aria-checked="String(m.id === selected)" @click="app.selectModel(kind, m)">
        <span class="radio-mark"><svg aria-hidden="true"><use href="#i-tick" /></svg></span>
        <span class="t">
          <b>{{ m.name }}<span v-if="m.badge" :class="['badge', m.badge === 'Советуем' ? 'hot' : '']">{{ m.badge }}</span><span v-if="locked(m)" class="badge hot">{{ m.min_plan === 'pro' ? 'Pro' : 'Plus' }}</span></b>
          <span>{{ m.hint }}</span>
        </span>
        <span class="end">
          <svg v-if="m.fast" class="ic-s" aria-label="быстрая"><use href="#i-bolt" /></svg>
          <svg v-if="locked(m)" class="ic-s" aria-label="закрыто"><use href="#i-lock" /></svg>
          <span v-else class="num">{{ m.price }}</span>
        </span>
      </button>
    </li>
  </ul>
</template>

<script>
import { useApp } from '../stores/app'

export default {
  name: 'ModelsSheet',
  computed: {
    app() { return useApp() },
    kind() { return this.app.current === 'chat' ? 'text' : this.app.mode === 'tools' ? 'image' : this.app.mode },
    list() { return this.app.models[this.kind] || [] },
    selected() { return this.app.modelSel[this.kind] },
  },
  methods: { locked(m) { return this.app.isLocked(m) } },
}
</script>
