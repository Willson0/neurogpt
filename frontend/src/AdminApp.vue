<template>
  <div class="app">
    <div v-if="state === 'loading'" class="boot" role="status"><Sticker name="art" :size="96" /></div>
    <div v-else-if="state !== 'ready'" class="boot">
      <Sticker name="cry" :size="96" />
      <h3>{{ state === 'network' ? 'Нет соединения' : state === 'denied' ? 'Нет доступа' : 'Вход в админку' }}</h3>
      <p v-if="state === 'network'">Проверьте интернет и попробуйте ещё раз.</p>
      <p v-else-if="state === 'denied'">У этого аккаунта нет доступа к админке.</p>
      <button v-if="state === 'network'" type="button" class="pill-btn" @click="boot">Повторить</button>
      <button v-else-if="state === 'denied' || !browser" type="button" class="pill-btn" @click="toApp">В приложение</button>
      <!-- A plain browser: the admin signs in with the Telegram Login Widget. -->
      <div v-if="state === 'login' && browser" class="adm-login">
        <p>Войдите через Telegram под аккаунтом администратора.</p>
        <div ref="login" />
        <p v-if="loginError" class="err-text">{{ loginError }}</p>
      </div>
      <p v-else-if="state === 'login'">Откройте приложение из Telegram.</p>
    </div>

    <div v-else id="scroll" class="scroll">
      <AdminView />
    </div>

    <Toast />
  </div>
</template>

<script>
import { useApp } from './stores/app'
import { api, ApiError, webLogin } from './services/api'
import { tg } from './services/telegram'
import { theme } from './services/theme'
import Toast from './components/Toast.vue'
import Sticker from './components/Sticker.vue'
import AdminView from './views/AdminView.vue'

export default {
  name: 'AdminApp',
  components: { Toast, Sticker, AdminView },
  data: () => ({ state: 'loading', loginError: '' }),
  computed: {
    app() { return useApp() },
    browser() { return !tg.initData },
  },
  mounted() {
    this.app.standalone = true
    this.app.stack = ['admin']
    tg.showBack(true, () => this.toApp())
    this.boot()
  },
  methods: {
    async boot() {
      this.state = 'loading'
      try {
        const [me, cfg] = await Promise.all([api.me(), api.publicConfig().catch(() => null)])
        if (cfg) theme.apply(cfg.theme)
        this.app.setUser(me)
        this.app.botUsername = cfg?.bot_username || ''
        this.state = me.is_admin ? 'ready' : 'denied'
      } catch (e) {
        const status = e instanceof ApiError ? e.status : 0
        this.state = status === 401 || status === 403 ? 'login' : 'network'
        if (this.state === 'login' && this.browser) this.$nextTick(this.mountLogin)
      }
    },
    toApp() { location.href = '/' },
    /** Telegram Login Widget. The server checks its signature and lets only the admin in. */
    async mountLogin() {
      const box = this.$refs.login
      if (!box || box.childElementCount) return
      if (webLogin.get()) { webLogin.clear(); this.loginError = 'Вход устарел или у этого аккаунта нет доступа. Войдите ещё раз.' }
      let bot = ''
      try { bot = (await api.publicConfig()).bot_username } catch { /* shown below */ }
      if (!bot) { this.loginError = this.loginError || 'Вход через браузер не настроен: на сервере не задано имя бота.'; return }
      window.ngTelegramLogin = (user) => { webLogin.set(user); this.loginError = ''; this.boot() }
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
  },
}
</script>

<style>
.boot { position: relative; z-index: 1; display: grid; place-content: center; justify-items: center; gap: var(--space-sm); text-align: center; padding: var(--space-lg); }
.boot p { color: var(--color-muted); font-size: var(--text-2); max-width: 28ch; }
</style>
