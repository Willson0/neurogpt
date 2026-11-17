<template>
  <div class="hero-stk"><Sticker name="gift" :size="96" glow glow-color="--plasma-1" sparkle /></div>
  <h3 id="sheetTitle">Пригласить друга</h3>
  <p class="lead">Вам {{ percent }}% от каждой покупки друга на реферальный баланс. {{ tokensLine }}</p>
  <p v-if="app.user && app.user.ref_balance" class="lead">Реферальный баланс: <b class="num">{{ app.user.ref_balance.toLocaleString('ru-RU') }} ₽</b></p>
  <button v-if="app.inviteLink()" type="button" class="linkbox glass" aria-label="Скопировать ссылку" @click="copy"><span>{{ app.inviteLink() }}</span><svg class="ic-s" aria-hidden="true"><use href="#i-copy" /></svg></button>
  <button type="button" class="bigbtn" @click="send"><span class="lbl">Пригласить</span></button>
  <button type="button" class="ghostbtn" @click="copy">Скопировать ссылку</button>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from '../components/Sticker.vue'

export default {
  name: 'InviteSheet',
  components: { Sticker },
  computed: {
    app() { return useApp() },
    percent() { return this.app.bonus.referral_percent ?? 20 },
    // the referral tokens come with the friend's first purchase (to the friend or to the inviter, as set in the admin)
    tokensLine() {
      const n = this.app.bonus.referral_tokens ?? 50
      if (!n) return ''
      return this.app.bonus.referral_tokens_to === 'inviter'
        ? `И ещё ${n} токенов, когда друг впервые что-то купит`
        : `Другу ${n} токенов после первой покупки`
    },
  },
  methods: {
    send() { this.app.invite(); this.app.closeSheet() },
    async copy() { await this.app.copyInvite(); this.app.closeSheet() },
  },
}
</script>
