<template>
  <div class="view adm" data-view="admin">
    <header class="adm-head">
      <button type="button" class="adm-back glass" :aria-label="app.canGoBack ? 'Назад' : 'В приложение'" @click="leave"><svg class="ic-s" aria-hidden="true"><use href="#i-back" /></svg></button>
      <h2>Админка</h2>
      <button v-if="webLogin" type="button" class="adm-btn soft sm" @click="logout">Выйти</button>
    </header>
    <div class="adm-sidebar">
      <AdminSearch :sections="sections" @select="choose" />
      <nav ref="navigation" class="filters adm-nav" v-drag-scroll aria-label="Разделы админки">
        <button v-for="s in sections" :key="s.id" type="button" class="chip" :aria-pressed="String(current === s.id)" @click="go(s.id)">{{ s.name }}</button>
      </nav>
    </div>
    <div ref="content" class="adm-body">
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
import AdminSearch from '../admin/AdminSearch.vue'
import { matchingLabel } from '../admin/searchText'
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

export default {
  name: 'AdminView',
  components: { AdminSearch },
  data: () => ({
    views: markRaw({
      dashboard: AdminDashboard, content: AdminContent, models: AdminModels, tools: AdminTools, showcase: AdminShowcase,
      pricing: AdminPricing, settings: AdminSettings, theme: AdminTheme, users: AdminUsers, payments: AdminPayments, promos: AdminPromos, renewal: AdminRenewal, providerEvents: AdminProviderEvents,
    }),
    current: 'dashboard', contentUser: '', destination: {}, destinationKey: 0,
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
      const { context, query } = this.destination
      const params = {}
      if (['content', 'payments'].includes(this.current)) params.initialUser = this.contentUser
      if (['providerEvents', 'users'].includes(this.current)) params.initialQuery = query
      if (['content', 'payments', 'users', 'models', 'showcase', 'tools'].includes(this.current)) {
        params.searchContext = context
      }
      return params
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
  mounted() {
    this.revealActiveSection()
    document.fonts?.ready.then(() => this.revealActiveSection())
  },
  beforeUnmount() { this.clearSearchTarget() },
  watch: {
    current() { this.$nextTick(this.revealActiveSection) },
  },
  methods: {
    revealActiveSection() {
      const nav = this.$refs.navigation
      const button = nav?.querySelector('[aria-pressed="true"]')
      if (!button) return

      const container = nav.getBoundingClientRect()
      const active = button.getBoundingClientRect()
      if (nav.scrollWidth > nav.clientWidth) {
        const right = container.left + nav.clientWidth
        if (active.left < container.left) nav.scrollLeft += active.left - container.left
        else if (active.right > right) nav.scrollLeft += active.right - right
      }
      if (nav.scrollHeight > nav.clientHeight) {
        if (active.top < container.top) nav.scrollTop += active.top - container.top
        else if (active.bottom > container.top + nav.clientHeight) nav.scrollTop += active.bottom - container.top - nav.clientHeight
      }
    },
    async choose(item) {
      const existing = item.id === this.current && item.target && matchingLabel(this.$refs.content, item.target)
      if (existing) {
        this.clearSearchTarget()
        this.showSearchTarget(existing)
        return
      }

      this.go(item.id)
      this.destination = item
      this.destinationKey++
      await this.$nextTick()
      if (this.destination !== item && this.destination.key !== item.key) return
      if (item.target) this.waitForSearchTarget(item.target)
      else this.$refs.navigation?.querySelector('[aria-pressed="true"]')?.focus({ preventScroll: true })
    },
    waitForSearchTarget(title) {
      const content = this.$refs.content
      if (!content) return
      const reveal = () => {
        const target = matchingLabel(content, title)
        if (!target) return false
        this.clearSearchTarget()
        this.showSearchTarget(target)
        return true
      }
      if (reveal()) return
      this.searchObserver = new MutationObserver(reveal)
      this.searchObserver.observe(content, { childList: true, subtree: true, characterData: true })
      this.searchTimer = setTimeout(() => this.clearSearchTarget(), 10000)
    },
    showSearchTarget(target) {
      this.highlightedTarget = target
      target.classList.add('adm-search-hit')
      if (!target.hasAttribute('tabindex')) target.tabIndex = -1
      target.focus({ preventScroll: true })
      target.scrollIntoView({ block: 'center', inline: 'nearest' })
    },
    clearSearchTarget() {
      clearTimeout(this.searchTimer)
      this.searchObserver?.disconnect()
      this.searchObserver = null
      this.highlightedTarget?.classList.remove('adm-search-hit')
      this.highlightedTarget = null
    },
    go(id, scroll = true) {
      this.clearSearchTarget()
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

