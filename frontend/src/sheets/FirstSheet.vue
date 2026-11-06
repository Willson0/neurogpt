<template>
  <div class="hero-stk"><TgsPlayer name="token" :size="160" /></div>
  <h3 id="sheetTitle">Дарим {{ app.bonus.signup || 50 }} токенов</h3>
  <p class="lead">Хватит на {{ Math.floor((app.bonus.signup || 50) / (app.models.image[0]?.price || 8)) }} картинок. Напишите идею и нажмите искру справа от дока.</p>
  <button type="button" class="bigbtn shine" @click="go"><span class="lbl">Попробовать</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import TgsPlayer from '../components/TgsPlayer.vue'
import { tg } from '../services/telegram'

export default {
  name: 'FirstSheet',
  components: { TgsPlayer },
  computed: { app() { return useApp() } },
  mounted() { tg.haptic('success') },
  methods: {
    go() {
      this.app.closeSheet()
      this.app.goTab('home')
      setTimeout(() => document.getElementById('prompt')?.focus(), 380)
    },
  },
}
</script>
