<template>
  <section class="adm-sec">
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else>
      <div class="adm-card glass">
        <h3>Какую тему настраиваем</h3>
        <div class="filters"><button v-for="(n, k) in schemes" :key="k" type="button" class="chip" style="flex:1" :aria-pressed="String(scheme === k)" @click="setScheme(k)">{{ n }}</button></div>
        <p class="adm-note">Приложение показывает тёмную или светлую тему по настройке Telegram у пользователя. Цвета меняются сразу на этом экране, а для всех после кнопки «Сохранить».</p>
      </div>

      <div class="adm-card glass">
        <h3>Готовые гаммы</h3>
        <div class="adm-bar">
          <button v-for="p in presets" :key="p.name" type="button" class="adm-swatch" @click="usePreset(p)"><i v-for="c in p.dark.slice(1)" :key="c" :style="{ background: c }" />{{ p.name }}</button>
        </div>
      </div>

      <div class="adm-preview" aria-hidden="true">
        <h3>Так выглядит приложение</h3>
        <p style="color:var(--color-muted);font-size:var(--text-2)">Второстепенный текст и <span style="color:var(--color-accent)">акцентный цвет</span>.</p>
        <div class="adm-bar">
          <span class="chip" aria-pressed="true">Выбрано</span><span class="chip">Обычная</span><span class="badge">Советуем</span><span class="badge hot">Топ</span>
          <span class="pill-btn hot">Улучшить</span><span class="amt plus">+50</span><span style="color:var(--color-danger);font-size:var(--text-cap)">Ошибка</span>
        </div>
        <div class="glass" style="border-radius:var(--radius-ctl);padding:var(--space-sm)">Стеклянная карточка</div>
        <span class="bigbtn"><span class="lbl">Главная кнопка</span></span>
        <span class="bigbtn gold"><span class="lbl">Кнопка оплаты</span></span>
      </div>

      <div v-for="g in groups" :key="g.name" class="adm-card glass">
        <h3>{{ g.name }}</h3>
        <div class="adm-colors">
          <label v-for="c in g.vars" :key="c.id" :class="['adm-color', draft[scheme][c.id] ? 'set' : '']">
            <input type="color" :value="draft[scheme][c.id] || defaults[c.id] || '#000000'" :aria-label="c.name" @input="set(c.id, $event.target.value)">
            <span>{{ c.name }}<small>{{ draft[scheme][c.id] || 'по умолчанию' }}</small></span>
            <button v-if="draft[scheme][c.id]" type="button" class="x" style="border:0;background:transparent;color:var(--color-muted);margin-left:auto" :aria-label="'Вернуть цвет: ' + c.name" @click.prevent="set(c.id, null)"><svg class="ic-s" aria-hidden="true"><use href="#i-redo" /></svg></button>
          </label>
        </div>
      </div>

      <div class="adm-actions">
        <button type="button" class="adm-btn" :disabled="busy || !dirty" @click="save">Сохранить для всех</button>
        <button type="button" class="adm-btn soft" :disabled="!dirty" @click="revert">Отменить изменения</button>
        <button type="button" class="adm-btn danger" @click="reset">Вернуть стандартные цвета</button>
      </div>
    </template>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { theme, toHex } from '../services/theme'
import { tg } from '../services/telegram'
import { section } from './shared'

const ACCENT = ['--color-accent', '--plasma-1', '--plasma-2', '--plasma-3']
const copy = (t) => ({ dark: { ...(t?.dark || {}) }, light: { ...(t?.light || {}) } })

export default {
  name: 'AdminTheme',
  mixins: [section],
  data: () => ({
    schemes: { dark: 'Тёмная', light: 'Светлая' }, scheme: 'dark', saved: copy(), draft: copy(), defaults: {},
    presets: [
      { name: 'Океан', dark: ['#4da3ff', '#3d8bff', '#38b6ff', '#8fe9ff'], light: ['#1f6fd6', '#3d8bff', '#38b6ff', '#8fe9ff'] },
      { name: 'Лес', dark: ['#4cc38a', '#2fb37a', '#62cf7c', '#d6ec6f'], light: ['#1c8556', '#2fb37a', '#62cf7c', '#d6ec6f'] },
      { name: 'Аметист', dark: ['#b78cff', '#9a6bff', '#c874ff', '#ff9ad6'], light: ['#7a45d6', '#9a6bff', '#c874ff', '#ff9ad6'] },
      { name: 'Роза', dark: ['#ff7aa8', '#ff5c8a', '#ff8a8f', '#ffc7ab'], light: ['#cf2f6a', '#ff5c8a', '#ff8a8f', '#ffc7ab'] },
    ],
    groups: [
      { name: 'Акцент и свечение', vars: [
        { id: '--color-accent', name: 'Акцент' }, { id: '--color-accent-ink', name: 'Текст на акценте' }, { id: '--color-focus', name: 'Обводка фокуса' },
        { id: '--plasma-1', name: 'Свечение 1' }, { id: '--plasma-2', name: 'Свечение 2' }, { id: '--plasma-3', name: 'Свечение 3' }, { id: '--on-plasma', name: 'Текст на градиенте' },
      ] },
      { name: 'Фон и карточки', vars: [
        { id: '--color-paper', name: 'Фон приложения' }, { id: '--color-page', name: 'Поля вокруг' }, { id: '--color-surface', name: 'Карточки и окна' },
        { id: '--color-surface-2', name: 'Поля и плашки' }, { id: '--color-rule', name: 'Линии' },
      ] },
      { name: 'Текст и кнопки', vars: [
        { id: '--color-ink', name: 'Основной текст' }, { id: '--color-muted', name: 'Второстепенный текст' }, { id: '--color-cta', name: 'Главная кнопка' }, { id: '--color-cta-ink', name: 'Текст главной кнопки' },
      ] },
      { name: 'Состояния', vars: [{ id: '--gold', name: 'Золото' }, { id: '--color-success', name: 'Успех' }, { id: '--color-danger', name: 'Ошибка' }] },
    ],
  }),
  computed: { dirty() { return JSON.stringify(this.draft) !== JSON.stringify(this.saved) } },
  mounted() {
    this.scheme = document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'
    this.load()
  },
  beforeUnmount() {
    // unsaved colors and the previewed scheme do not leave this screen
    theme.apply(this.saved, false)
    tg.applyTheme()
  },
  methods: {
    load() { return this.fetch(async () => { this.saved = copy((await adminApi.settings()).data.theme); this.draft = copy(this.saved); this.readDefaults() }) },
    /** The built-in colors of the scheme on screen, read with the override switched off. */
    readDefaults() {
      const el = document.getElementById('ng-theme')
      const css = el ? el.textContent : ''
      if (el) el.textContent = ''
      const style = getComputedStyle(document.documentElement)
      this.defaults = Object.fromEntries(this.groups.flatMap((g) => g.vars).map((c) => [c.id, toHex(style.getPropertyValue(c.id).trim())]))
      if (el) el.textContent = css
    },
    setScheme(s) {
      this.scheme = s
      document.documentElement.dataset.theme = s
      this.readDefaults()
      theme.apply(this.draft, false)
    },
    set(id, value) {
      if (value) this.draft[this.scheme][id] = value
      else delete this.draft[this.scheme][id]
      theme.apply(this.draft, false)
    },
    usePreset(p) {
      for (const s of ['dark', 'light']) ACCENT.forEach((id, i) => { this.draft[s][id] = p[s][i] })
      for (const s of ['dark', 'light']) this.draft[s]['--color-focus'] = p[s][0]
      theme.apply(this.draft, false)
    },
    revert() { this.draft = copy(this.saved); theme.apply(this.draft, false) },
    reset() { this.draft = copy(); theme.apply(this.draft, false) },
    save() {
      return this.run(async () => {
        this.saved = copy((await adminApi.saveSettings({ theme: this.draft })).data.theme)
        this.draft = copy(this.saved)
        theme.apply(this.saved)
      }, 'Цвета сохранены')
    },
  },
}
</script>
