<template>
  <div class="hero-stk"><TgsPlayer name="token" :size="160" /></div>
  <h3 id="sheetTitle">У вас {{ app.balance }} {{ plural(app.balance, 'токен', 'токена', 'токенов') }}</h3>
  <p class="lead">Хватит на {{ Math.floor(app.balance / (app.models.image[0]?.price || 8)) }} картинок. Напишите идею и нажмите «Создать».</p>
  <button type="button" class="bigbtn shine" @click="go"><span class="lbl">Попробовать</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import TgsPlayer from '../components/TgsPlayer.vue'
import { tg } from '../services/telegram'
import { plural } from '../services/format'

export default {
  name: 'FirstSheet',
  components: { TgsPlayer },
  computed: { app() { return useApp() } },
  mounted() { tg.haptic('success') },
  methods: {
    plural,
    go() {
      this.app.closeSheet()
      this.app.goTab('home')
      setTimeout(() => document.getElementById('prompt')?.focus(), 380)
    },
  },
}
</script>
