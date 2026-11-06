<template>
  <div class="hero-stk"><Sticker name="thinking" :size="96" glow glow-color="--plasma-2" /></div>
  <h3 id="sheetTitle">Не можем это нарисовать</h3>
  <p class="lead">Запрос не прошёл правила сервиса. Токены вернулись на баланс, попробуйте сказать иначе.</p>
  <button type="button" class="bigbtn" @click="app.closeSheet()"><span class="lbl">Изменить запрос</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from '../components/Sticker.vue'

export default {
  name: 'FilterSheet',
  components: { Sticker },
  computed: { app() { return useApp() } },
}
</script>
