<template>
  <div class="hero-stk"><Sticker name="gift" :size="96" glow glow-color="--plasma-1" sparkle /></div>
  <h3 id="sheetTitle">Пригласить друга</h3>
  <p class="lead">Вам {{ app.bonus.referral || 50 }} токенов, когда друг сделает первую генерацию. Другу {{ app.bonus.signup || 50 }} токенов на старте</p>
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
  computed: { app() { return useApp() } },
  methods: {
    send() { this.app.invite(); this.app.closeSheet() },
    async copy() { await this.app.copyInvite(); this.app.closeSheet() },
  },
}
</script>
