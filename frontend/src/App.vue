<template>
  <div class="app">
    <PlasmaCanvas :dim="!['home', 'create'].includes(app.current)" />

    <div v-if="app.booting && !app.user" class="boot" role="status"><Sticker name="art" :size="96" glow glow-color="--plasma-2" /></div>
    <!-- A payment link opened outside Telegram: what is sold and a button that opens it in Telegram. -->
    <PayPreview v-else-if="app.bootError && !app.user && unauthorized && payLinkId" :item-id="payLinkId" />
    <div v-else-if="app.bootError && !app.user" class="boot">
      <Sticker name="cry" :size="96" glow glow-color="--plasma-1" />
      <h3>{{ !unauthorized ? 'Нет соединения' : 'Откройте приложение из Telegram' }}</h3>
      <p>{{ unauthorized ? 'Нужна авторизация через Telegram, откройте бота и нажмите кнопку.' : 'Проверьте интернет и попробуйте ещё раз.' }}</p>
      <button v-if="!unauthorized" type="button" class="pill-btn" @click="app.boot()">Повторить</button>
      <!-- A plain browser: the admin signs in on the admin page. -->
      <a v-else-if="browser" class="pill-btn" href="/admin">Вход для администратора</a>
    </div>

    <template v-else>
      <div id="scroll" ref="scroll" class="scroll">
        <!-- Pushed screens (top-up, plans, history, generation) hide the dock. Telegram shows its back arrow in the header;
             a plain browser or an old client had no way out except Esc, so the app draws its own. -->
        <div v-if="app.canGoBack && !nativeBack" class="screen-back">
          <button type="button" class="glass" data-track="Назад" @click="app.back()"><svg class="ic-s" aria-hidden="true"><use href="#i-back" /></svg>Назад</button>
        </div>
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
import { useApp } from './stores/app'
import { tg } from './services/telegram'
import { payLinkItem } from './services/deeplink'
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
import PayView from './views/PayView.vue'
import PayPreview from './components/PayPreview.vue'

export default {
  name: 'App',
  components: {
    PlasmaCanvas, BottomBar, Toast, FxLayer, Sticker, SheetHost,
    home: HomeView, create: CreateView, gallery: GalleryView, profile: ProfileView,
    history: HistoryView, topup: TopupView, plans: PlansView, pay: PayView, PayPreview,
  },

  computed: {
    app() { return useApp() },
    unauthorized() { return this.app.bootError?.status === 401 },
    browser() { return !tg.initData },
    nativeBack() { return tg.hasBackButton },
    payLinkId() { return payLinkItem() },
  },
  watch: {
    'app.current'() { this.$nextTick(() => { if (this.$refs.scroll) this.$refs.scroll.scrollTop = 0 }) },
    'app.canGoBack': { immediate: true, handler() { this.syncBack() } },
    'app.sheet'() { this.syncBack() },
  },
  mounted() {
    this.app.boot()
    tg.onVisible(() => { this.app.resumePayment(); this.app.refreshUser() })
    // keyboard: Esc goes back on screens that have no sheet open (sheets close themselves)
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !this.app.sheet && this.app.canGoBack) this.app.back() })
  },
  methods: {
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
