<template>
  <div class="app">
    <PlasmaCanvas :dim="!['home', 'create'].includes(app.current)" :still="app.current === 'admin'" />

    <div v-if="app.booting && !app.user" class="boot" role="status"><Sticker name="art" :size="96" glow glow-color="--plasma-2" /></div>
    <div v-else-if="app.bootError && !app.user" class="boot">
      <Sticker name="cry" :size="96" glow glow-color="--plasma-1" />
      <h3>{{ !unauthorized ? 'Нет соединения' : adminLink && browser ? 'Вход в админку' : 'Откройте приложение из Telegram' }}</h3>
      <p v-if="!(unauthorized && adminLink && browser)">{{ unauthorized ? 'Нужна авторизация через Telegram, откройте бота и нажмите кнопку.' : 'Проверьте интернет и попробуйте ещё раз.' }}</p>
      <button v-if="!unauthorized" type="button" class="pill-btn" @click="app.boot()">Повторить</button>
      <!-- A plain browser: the admin signs in with the Telegram Login Widget. -->
      <div v-else-if="browser" class="adm-login">
        <p>{{ adminLink ? 'Войдите через Telegram под аккаунтом администратора.' : 'Администратор может войти через Telegram прямо в браузере.' }}</p>
        <div ref="login" />
        <p v-if="loginError" class="err-text">{{ loginError }}</p>
      </div>
    </div>

    <template v-else>
      <div id="scroll" ref="scroll" class="scroll">
        <component :is="app.current" :key="app.current" :class="app.transition" />
      </div>
      <BottomBar />
      <SheetHost />
    </template>

    <Toast />
    <FxLayer />
  </div>
</template>

<script>
import { useApp, adminLink } from './stores/app'
import { tg } from './services/telegram'
import PlasmaCanvas from './components/PlasmaCanvas.vue'
import BottomBar from './components/BottomBar.vue'
import Toast from './components/Toast.vue'
import FxLayer from './components/FxLayer.vue'
import Sticker from './components/Sticker.vue'
import SheetHost from './sheets/SheetHost.vue'
import HomeView from './views/HomeView.vue'
import CreateView from './views/CreateView.vue'
import GalleryView from './views/GalleryView.vue'
import ProfileView from './views/ProfileView.vue'
import HistoryView from './views/HistoryView.vue'
import TopupView from './views/TopupView.vue'
import PlansView from './views/PlansView.vue'
import AdminView from './views/AdminView.vue'
import { api, webLogin } from './services/api'
import { theme } from './services/theme'

export default {
  name: 'App',
  components: {
    PlasmaCanvas, BottomBar, Toast, FxLayer, Sticker, SheetHost,
    home: HomeView, create: CreateView, gallery: GalleryView, profile: ProfileView,
    history: HistoryView, topup: TopupView, plans: PlansView, admin: AdminView,
  },
  data: () => ({ loginError: '', adminLink: adminLink() }),
  computed: {
    app() { return useApp() },
    unauthorized() { return this.app.bootError?.status === 401 },
    browser() { return !tg.initData },
  },
  watch: {
    'app.current'() { this.guardAdmin(); this.$nextTick(() => { if (this.$refs.scroll) this.$refs.scroll.scrollTop = 0 }) },
    'app.canGoBack': { immediate: true, handler() { this.syncBack() } },
    'app.sheet'() { this.syncBack() },
    unauthorized(v) { if (v && this.browser) this.$nextTick(this.mountLogin) },
    // a non-admin cannot stay on the admin screen, whatever put it on the stack (the /admin link included)
    'app.user'() { this.guardAdmin() },
  },
  mounted() {
    this.app.boot()
    tg.onVisible(() => { this.app.resumePayment(); this.app.refreshUser() })
    // keyboard: Esc goes back on screens that have no sheet open (sheets close themselves)
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !this.app.sheet && this.app.canGoBack) this.app.back() })
  },
  methods: {
    guardAdmin() {
      if (this.app.current !== 'admin' || !this.app.user || this.app.isAdmin) return
      this.app.goTab('home')
      if (adminLink()) { history.replaceState(null, '', '/'); this.app.toast('У этого аккаунта нет доступа к админке', 'cry') }
    },
    /** Telegram Login Widget. The server checks its signature and lets only the admin in. */
    async mountLogin() {
      const box = this.$refs.login
      if (!box || box.childElementCount) return
      if (webLogin.get()) { webLogin.clear(); this.loginError = 'Вход устарел или у этого аккаунта нет доступа. Войдите ещё раз.' }
      let bot = ''
      try { const cfg = await api.publicConfig(); bot = cfg.bot_username; theme.apply(cfg.theme) } catch { /* shown below */ }
      if (!bot) { this.loginError = this.loginError || 'Вход через браузер не настроен: на сервере не задано имя бота.'; return }
      window.ngTelegramLogin = (user) => { webLogin.set(user); this.loginError = ''; this.app.boot() }
      const s = document.createElement('script')
      s.async = true
      s.src = 'https://telegram.org/js/telegram-widget.js?22'
      s.dataset.telegramLogin = bot
      s.dataset.size = 'large'
      s.dataset.radius = '14'
      s.dataset.requestAccess = 'write'
      s.dataset.onauth = 'ngTelegramLogin(user)'
      box.appendChild(s)
    },
    syncBack() {
      tg.showBack(this.app.canGoBack || !!this.app.sheet, () => this.app.back())
    },
  },
}
</script>

<style>
.boot { position: relative; z-index: 1; display: grid; place-content: center; justify-items: center; gap: var(--space-sm); text-align: center; padding: var(--space-lg); }
.boot p { color: var(--color-muted); font-size: var(--text-2); max-width: 28ch; }
</style>
