<template>
  <div class="view adm" data-view="admin">
    <header class="adm-head">
      <button type="button" class="adm-back glass" :aria-label="app.canGoBack ? 'Назад' : 'В приложение'" @click="leave"><svg class="ic-s" aria-hidden="true"><use href="#i-back" /></svg></button>
      <h2>Админка</h2>
      <button v-if="webLogin" type="button" class="adm-btn soft sm" @click="logout">Выйти</button>
    </header>
    <form class="adm-search" role="search" @submit.prevent="openFirst" @keydown.esc.stop="search = ''">
      <label class="visually-hidden" for="admin-search">Поиск в админке</label>
      <input id="admin-search" v-model.trim="search" type="search" maxlength="128" placeholder="Найти раздел, настройку или запись API">
      <ul v-if="search" class="glass admin-search-results">
        <li v-for="item in searchResults" :key="item.id + (item.target || '')">
          <button type="button" @click="choose(item)">{{ item.name }}</button>
        </li>
        <li><button type="button" @click="choose({ id: 'providerEvents', query: search })">Искать в журнале API</button></li>
        <li><button type="button" @click="choose({ id: 'users', query: search })">Искать среди пользователей</button></li>
      </ul>
    </form>
    <nav class="filters adm-nav" v-drag-scroll aria-label="Разделы админки">
      <button v-for="s in sections" :key="s.id" type="button" class="chip" :aria-pressed="String(current === s.id)" @click="go(s.id)">{{ s.name }}</button>
    </nav>
    <div class="adm-body">
      <component :is="views[current]" :key="current + contentUser + destinationKey"
        v-bind="viewProps" @content="showContent" @payments="showPayments" />
    </div>
  </div>
</template>

<script>
import { markRaw } from 'vue'
import { useApp } from '../stores/app'
import { webLogin } from '../services/api'
import { tg } from '../services/telegram'
import AdminDashboard from '../admin/AdminDashboard.vue'
import AdminContent from '../admin/AdminContent.vue'
import AdminModels from '../admin/AdminModels.vue'
import AdminTools from '../admin/AdminTools.vue'
import AdminShowcase from '../admin/AdminShowcase.vue'
import AdminPricing from '../admin/AdminPricing.vue'
import AdminSettings from '../admin/AdminSettings.vue'
import AdminTheme from '../admin/AdminTheme.vue'
import AdminUsers from '../admin/AdminUsers.vue'
import AdminProviderEvents from '../admin/AdminProviderEvents.vue'
import AdminRenewal from '../admin/AdminRenewal.vue'
import AdminPromos from '../admin/AdminPromos.vue'
import AdminPayments from '../admin/AdminPayments.vue'

const settings = [
  { id: 'settings', target: 'songs', name: 'Вкладка «Песня»', terms: 'suno музыка песни песню аудио включить флаг' },
  { id: 'settings', target: 'limits', name: 'Лимиты и таймауты', terms: 'таймаут загрузка размер фото очередь генераций ограничение' },
  { id: 'settings', target: 'styles', name: 'Стили картинок', terms: 'стиль пресет изображения' },
  { id: 'settings', target: 'translation', name: 'Перевод промптов видео', terms: 'английский kie промпт переводить' },
  { id: 'settings', target: 'support', name: 'Ссылка на поддержку', terms: 'telegram помощь контакт' },
]

const keywords = {
  dashboard: 'статистика сводка',
  providerEvents: 'логи ошибка ошибки журнал api kie suno replicate openai hydra openrouter код задача таймаут',
  content: 'генерации история фото видео песни аудио',
  models: 'провайдер генерация Suno Kling цена',
  showcase: 'фото видеосеты фотосеты пример витрина',
  pricing: 'токены баланс бонусы цены',
  settings: 'конфигурация',
  payments: 'юн касса юкасса yookassa webhook вебхук звезды оплата транзакции',
  renewal: 'повтор интервал попытки подписка продление автосписания срок',
  promos: 'скидки процент купон промокод',
  theme: 'оформление интерфейс тема',
  users: 'админы telegram аккаунты баланс',
}
const normalize = (value) => value.toLowerCase().replaceAll('ё', 'е').replace(/[^\p{L}\p{N}]+/gu, ' ').trim()

export default {
  name: 'AdminView',
  data: () => ({
    views: markRaw({
      dashboard: AdminDashboard, content: AdminContent, models: AdminModels, tools: AdminTools, showcase: AdminShowcase,
      pricing: AdminPricing, settings: AdminSettings, theme: AdminTheme, users: AdminUsers, payments: AdminPayments, promos: AdminPromos, renewal: AdminRenewal, providerEvents: AdminProviderEvents,
    }),
    current: 'dashboard', contentUser: '', search: '', destination: {}, destinationKey: 0,
    sections: [
      { id: 'dashboard', name: 'Обзор' }, { id: 'content', name: 'Работы и промпты' }, { id: 'models', name: 'Модели' }, { id: 'tools', name: 'Инструменты' },
      { id: 'showcase', name: 'Пресеты и новости' }, { id: 'pricing', name: 'Цены и бонусы' }, { id: 'settings', name: 'Настройки' },
      { id: 'payments', name: 'Платежи и ЮKassa' }, { id: 'renewal', name: 'Автопродление' }, { id: 'promos', name: 'Промокоды' }, { id: 'providerEvents', name: 'Журнал API' }, { id: 'theme', name: 'Цвета' }, { id: 'users', name: 'Пользователи' },
    ],
  }),
  computed: {
    app() { return useApp() },
    webLogin() { return !tg.initData && !!webLogin.get() },
    viewProps() {
      if (['content', 'payments'].includes(this.current)) return { initialUser: this.contentUser }
      if (this.current === 'settings') return { initialTarget: this.destination.target }
      if (['providerEvents', 'users'].includes(this.current)) return { initialQuery: this.destination.query }
      return {}
    },
    searchResults() {
      const text = normalize(this.search)
      if (!text) return []
      const words = text.split(' ')
      const candidates = [...settings, ...this.sections.map(item => ({ ...item, terms: keywords[item.id] || '' }))]
      return candidates.filter(item => words.every(word => normalize(item.name + ' ' + item.terms).includes(word)))
        .sort((a, b) => Number(normalize(b.name).includes(text)) - Number(normalize(a.name).includes(text)))
        .slice(0, 7)
    },
  },
  created() {
    // ?section=payments from a link wins over the section remembered in this tab
    const asked = new URLSearchParams(location.search).get('section')
    let saved = null
    try { saved = sessionStorage.getItem('ng_admin_tab') } catch { /* private mode */ }
    const pick = [asked, saved].find((id) => this.sections.some((s) => s.id === id))
    if (pick) this.go(pick, false)
  },
  methods: {
    openFirst() {
      if (this.search) this.choose(this.searchResults[0] || { id: this.search.startsWith('@') ? 'users' : 'providerEvents', query: this.search })
    },
    choose(item) {
      this.go(item.id)
      this.destination = item
      this.destinationKey++
      this.search = ''
    },
    go(id, scroll = true) {
      this.current = id
      this.destination = {}
      this.contentUser = ''
      try { sessionStorage.setItem('ng_admin_tab', id) } catch { /* private mode */ }
      if (scroll) document.getElementById('scroll')?.scrollTo({ top: 0 })
    },
    showContent(user) { this.go('content'); this.contentUser = user },
    showPayments(user) { this.go('payments'); this.contentUser = user },
    /** Opened by the /admin link there is nothing to go back to: the arrow leads into the app. */
    leave() {
      if (this.app.standalone) { location.href = '/'; return }
      if (this.app.canGoBack) return this.app.back()
      if (location.pathname !== '/') history.replaceState(null, '', '/')
      this.app.goTab('home')
    },
    logout() { webLogin.clear(); location.reload() },
  },
}
</script>

<style scoped>
.adm-search { position: relative; margin: 0 0 8px; }
.adm-search input { width: 100%; background: var(--color-surface-2); color: var(--color-ink); border: 1px solid var(--color-rule); border-radius: var(--radius-ctl); padding: 10px 12px; font: inherit; }
.admin-search-results { list-style: none; margin: 5px 0 0; padding: 5px; border-radius: var(--radius-ctl); }
.admin-search-results button { display: block; width: 100%; padding: 9px 10px; text-align: start; border-radius: var(--radius-ctl); }
.admin-search-results button:hover, .admin-search-results button:focus-visible { background: var(--color-surface-2); }
</style>
