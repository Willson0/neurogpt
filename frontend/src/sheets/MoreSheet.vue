<template>
  <h3 id="sheetTitle">Настройки</h3>
  <div style="display:grid;gap:var(--space-xs)">
    <span class="ctl-label">Стиль</span>
    <div class="filters" style="flex-wrap:wrap">
      <button v-for="s in styles" :key="s" type="button" class="chip" :aria-pressed="String(app.style === s)" @click="app.style = s">{{ s[0].toUpperCase() + s.slice(1) }}</button>
    </div>
  </div>
  <div style="display:grid;gap:var(--space-xs)">
    <span class="ctl-label">Сколько вариантов</span>
    <div class="filters">
      <button v-for="n in 4" :key="n" type="button" class="chip" style="flex:1" :aria-pressed="String(app.count === n)" @click="app.count = n">{{ n }}</button>
    </div>
  </div>
  <details>
    <summary class="ghostbtn" style="list-style:none;display:flex;align-items:center">Для опытных: негативный промпт</summary>
    <div class="prompt glass" style="margin-top:var(--space-xs)">
      <label for="neg" class="visually-hidden">Чего не должно быть</label>
      <textarea id="neg" v-model="app.negative" rows="2" maxlength="500" placeholder="Чего не должно быть на картинке" />
    </div>
  </details>
  <button type="button" class="bigbtn" @click="app.closeSheet()"><span class="lbl">Готово</span></button>
</template>

<script>
import { useApp } from '../stores/app'

export default {
  name: 'MoreSheet',
  data: () => ({ styles: ['любой', 'фото', 'аниме', 'акварель', '3D'] }),
  computed: { app() { return useApp() } },
}
</script>
