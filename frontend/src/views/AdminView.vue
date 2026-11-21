<template>
  <div class="view adm" data-view="admin">
    <header class="adm-head">
      <button type="button" class="adm-back glass" :aria-label="app.canGoBack ? 'Назад' : 'В приложение'" @click="leave"><svg class="ic-s" aria-hidden="true"><use href="#i-back" /></svg></button>
      <h2>Админка</h2>
      <button v-if="webLogin" type="button" class="adm-btn soft sm" @click="logout">Выйти</button>
    </header>
    <nav class="filters adm-nav" v-drag-scroll aria-label="Разделы админки">
      <button v-for="s in sections" :key="s.id" type="button" class="chip" :aria-pressed="String(current === s.id)" @click="go(s.id)">{{ s.name }}</button>
    </nav>
    <div class="adm-body">
      <component :is="views[current]" :key="current + contentUser" :initial-user="['content', 'payments'].includes(current) ? contentUser : undefined" @content="showContent" @payments="showPayments" />
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
import AdminRenewal from '../admin/AdminRenewal.vue'
import AdminPromos from '../admin/AdminPromos.vue'
import AdminPayments from '../admin/AdminPayments.vue'

export default {
  name: 'AdminView',
  data: () => ({
    views: markRaw({
      dashboard: AdminDashboard, content: AdminContent, models: AdminModels, tools: AdminTools, showcase: AdminShowcase,
      pricing: AdminPricing, settings: AdminSettings, theme: AdminTheme, users: AdminUsers, payments: AdminPayments, promos: AdminPromos, renewal: AdminRenewal,
    }),
    current: 'dashboard', contentUser: '',
    sections: [
      { id: 'dashboard', name: 'Обзор' }, { id: 'content', name: 'Работы и промпты' }, { id: 'models', name: 'Модели' }, { id: 'tools', name: 'Инструменты' },
      { id: 'showcase', name: 'Пресеты и новости' }, { id: 'pricing', name: 'Цены и бонусы' }, { id: 'settings', name: 'Настройки' },
      { id: 'payments', name: 'Платежи и ЮKassa' }, { id: 'renewal', name: 'Автопродление' }, { id: 'promos', name: 'Промокоды' }, { id: 'theme', name: 'Цвета' }, { id: 'users', name: 'Пользователи' },
    ],
  }),
  computed: {
    app() { return useApp() },
    webLogin() { return !tg.initData && !!webLogin.get() },
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
    go(id, scroll = true) {
      this.current = id
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
