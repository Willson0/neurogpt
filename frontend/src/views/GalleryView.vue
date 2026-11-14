<template>
  <div class="view" data-view="gallery">
    <div class="topbar">
      <h1 class="hero-h">Сеты<small>{{ app.setKind === 'video' ? 'Готовая идея для видео и один запуск' : 'Готовая идея, ваше фото и один запуск' }}</small></h1>
      <BalanceButton />
    </div>
    <div class="filters" role="tablist" aria-label="Вид сетов">
      <button v-for="k in kinds" :key="k.id" type="button" role="tab" class="chip" :aria-selected="String(app.setKind === k.id)" :aria-pressed="String(app.setKind === k.id)" @click="app.setKind = k.id">{{ k.name }}</button>
    </div>
    <div v-if="app.booting" class="masonry" aria-busy="true">
      <div class="col"><span v-for="h in [220, 160, 200]" :key="h" class="skel" :style="{ height: h + 'px' }" /></div>
      <div class="col"><span v-for="h in [150, 230, 170]" :key="h" class="skel" :style="{ height: h + 'px' }" /></div>
    </div>
    <div v-else-if="app.bootError" class="empty">
      <Sticker name="cry" :size="112" glow glow-color="--plasma-1" />
      <h3>Нет соединения</h3>
      <p>Генерации идут на сервере, результат придёт в чат.</p>
      <button type="button" class="pill-btn" @click="app.boot()">Повторить</button>
    </div>
    <div v-else-if="!app.setsShown.length" class="empty">
      <Sticker name="sleep" :size="120" glow glow-color="--plasma-3" />
      <h3>{{ app.setKind === 'video' ? 'Видеосетов пока нет' : 'Фотосетов пока нет' }}</h3>
      <p>Новые готовые идеи появятся здесь.</p>
    </div>
    <div v-else class="psets">
      <button v-for="(ps, k) in app.setsShown" :key="ps.id" type="button" class="pset" :style="{ '--i': k }" @click="open(ps)">
        <ArtCanvas :src="ps.cover_url" :seed="ps.seed" :palette="ps.palette" :w="180" :h="226" />
        <span class="pset-price"><svg class="token" aria-hidden="true"><use href="#i-token" /></svg>{{ app.setPrice(ps) }}</span>
        <span><b>{{ ps.name }}</b></span>
      </button>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { tg } from '../services/telegram'
import BalanceButton from '../components/BalanceButton.vue'
import Sticker from '../components/Sticker.vue'
import ArtCanvas from '../components/ArtCanvas.vue'

export default {
  name: 'GalleryView',
  components: { BalanceButton, Sticker, ArtCanvas },
  computed: {
    app() { return useApp() },
  },
  data: () => ({ kinds: [{ id: 'photo', name: 'Фотосеты' }, { id: 'video', name: 'Видеосеты' }] }),
  methods: { open(ps) { tg.haptic('light'); this.app.openSheet('photoset', { id: ps.id }) } },
}
</script>
