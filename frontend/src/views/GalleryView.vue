<template>
  <div class="view" data-view="gallery">
    <div class="topbar">
      <h1 class="hero-h">{{ kind === 'video' ? 'Видеосеты' : 'Фотосеты' }}<small>{{ kind === 'video' ? 'Превратите фото в видео по готовому сценарию' : 'Готовая идея, ваше фото и один запуск' }}</small></h1>
      <BalanceButton />
    </div>
    <div class="filters">
      <button v-for="option in kinds" :key="option.id" type="button" class="chip" :aria-pressed="String(kind === option.id)" @click="kind = option.id">{{ option.name }}</button>
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
    <div v-else-if="!sets.length" class="empty">
      <Sticker name="sleep" :size="120" glow glow-color="--plasma-3" />
      <h3>{{ kind === 'video' ? 'Видеосетов пока нет' : 'Фотосетов пока нет' }}</h3>
      <p>Новые готовые идеи появятся здесь.</p>
    </div>
    <div v-else class="psets">
      <button v-for="(ps, k) in sets" :key="ps.id" type="button" class="pset" :style="{ '--i': k }" @click="open(ps)">
        <video v-if="videoExample(ps)" :src="ps.cover_url" class="pset-video" muted playsinline preload="metadata" />
        <ArtCanvas v-else :src="ps.cover_url" :seed="ps.seed" :palette="ps.palette" :w="180" :h="226" />
        <span class="pset-price"><svg class="token" aria-hidden="true"><use href="#i-token" /></svg>{{ price }}</span>
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
  data: () => ({ kind: 'image', kinds: [{ id: 'image', name: 'Фотосеты' }, { id: 'video', name: 'Видеосеты' }] }),
  computed: {
    app() { return useApp() },
    sets() { return this.app.photosets.filter(ps => (ps.kind || 'image') === this.kind) },
    price() { const model = this.app.models[this.kind].find(m => m.id === this.app.modelSel[this.kind]) || this.app.models[this.kind][0]; return model?.durations?.[0]?.price ?? model?.price ?? '—' },
  },
  methods: { videoExample(ps) { return ps.kind === 'video' && /\.(mp4|webm|mov)(?:$|[?#])/i.test(ps.cover_url || '') }, open(ps) { tg.haptic('light'); this.app.openSheet('photoset', { id: ps.id }) } },
}
</script>

<style scoped>
.filters { margin-bottom: 14px; }
.pset-video { display: block; object-fit: cover; width: 100%; aspect-ratio: 180 / 226; }
</style>
