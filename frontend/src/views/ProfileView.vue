<template>
  <div class="view" data-view="profile">
    <div class="me">
      <div class="avatar">
        <img v-if="u.photo_url" :src="u.photo_url" alt="" style="width:100%;height:100%;border-radius:inherit;object-fit:cover" referrerpolicy="no-referrer">
        <span v-else>{{ (u.name || '?')[0] }}</span>
      </div>
      <h2>{{ u.name }}</h2>
      <span v-if="u.username" class="handle">@{{ u.username }}</span>
      <span class="bal-big"><svg class="token" aria-hidden="true"><use href="#i-token" /></svg><Odometer :value="app.balance" /></span>
      <span class="handle">{{ planLine }}</span>
    </div>
    <div class="duo">
      <button type="button" class="tilebtn glass" @click="app.push('topup')"><Sticker name="money" :size="36" />Пополнить</button>
      <button type="button" class="tilebtn glass" @click="app.openSheet('invite')"><Sticker name="gift" :size="36" />Пригласить</button>
    </div>
    <div class="bento">
      <div class="stat glass"><span>Создано</span><b>{{ fmt(u.stats ? u.stats.created : 0) }}</b></div>
      <div class="stat glass"><span>Потрачено</span><b>{{ fmt(u.stats ? u.stats.spent : 0) }}</b></div>
      <div class="stat glass"><span>Серия</span><b>{{ u.streak || 0 }} <Sticker name="fire" :size="22" /></b></div>
    </div>
    <ul class="list glass">
      <li>
        <button type="button" class="row" @click="app.push('plans')">
          <span class="sq hot"><svg class="ic-s" aria-hidden="true"><use href="#i-crown" /></svg></span>
          <span class="t"><b>Подписка</b></span>
          <span class="end">{{ app.planName }}<svg class="chev" aria-hidden="true"><use href="#i-chev" /></svg></span>
        </button>
      </li>
      <li>
        <button type="button" class="row" @click="app.push('history')">
          <span class="sq"><svg class="ic-s" aria-hidden="true"><use href="#i-history" /></svg></span>
          <span class="t"><b>История операций</b></span>
          <span class="end"><svg class="chev" aria-hidden="true"><use href="#i-chev" /></svg></span>
        </button>
      </li>
      <li v-if="support">
        <button type="button" class="row" @click="openSupport">
          <span class="sq"><svg class="ic-s" aria-hidden="true"><use href="#i-help" /></svg></span>
          <span class="t"><b>Помощь и документы</b></span>
          <span class="end"><svg class="chev" aria-hidden="true"><use href="#i-chev" /></svg></span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { fmt } from '../services/format'
import { tg } from '../services/telegram'
import Sticker from '../components/Sticker.vue'
import Odometer from '../components/Odometer.vue'

export default {
  name: 'ProfileView',
  components: { Sticker, Odometer },
  data: () => ({ support: import.meta.env.VITE_SUPPORT_URL || '' }),
  computed: {
    app() { return useApp() },
    u() { return this.app.user || {} },
    planLine() {
      const u = this.u
      if (!u.plan || u.plan === 'free' || !u.plan_expires_at) return 'Тариф Free'
      return `${this.app.planName}, до ${new Date(u.plan_expires_at).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}`
    },
  },
  methods: {
    fmt,
    openSupport() { /^https:\/\/t\.me\//.test(this.support) ? tg.openTelegramLink(this.support) : tg.openLink(this.support) },
  },
}
</script>
