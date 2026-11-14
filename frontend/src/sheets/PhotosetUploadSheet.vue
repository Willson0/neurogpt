<template>
  <ArtCanvas v-if="ps" :src="ps.cover_url" :seed="ps.seed" :palette="ps.palette" :w="320" :h="400" cls="pset-img" style="aspect-ratio:16 / 10" />
  <h3 id="sheetTitle">{{ ps ? ps.name : '' }}</h3>
  <p class="pset-cost">Списание {{ tok(price) }}</p>
  <label class="pill-btn psetfile">
    <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" @change="pick">
    <svg class="ic-s" aria-hidden="true"><use href="#i-clip" /></svg><span>{{ file ? file.name : video ? 'Фото для первого кадра (по желанию)' : 'Выбрать фото' }}</span>
  </label>
  <p v-if="video && !file" class="pset-cost">Без фото видео создаётся только по идее сета.</p>
  <div class="prompt glass">
    <label for="psetExtra" class="visually-hidden">Дополнительный промпт</label>
    <textarea id="psetExtra" v-model="extra" rows="2" maxlength="500"   :placeholder="video ? 'Что добавить: движение, настроение, детали' : 'Что добавить: цвет, фон, настроение'" />
  </div>
  <button type="button" class="bigbtn shine" :disabled="app.busy" @click="go"><span class="lbl">Отправить на генерацию</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import ArtCanvas from '../components/ArtCanvas.vue'
import { tok } from '../services/format'

export default {
  name: 'PhotosetUploadSheet',
  components: { ArtCanvas },
  props: { id: { type: Number, required: true } },
  data: () => ({ file: null, extra: '' }),
  computed: {
    app() { return useApp() },
    ps() { return this.app.photosets.find((p) => p.id === this.id) },
    video() { return this.ps?.kind === 'video' },
    price() { return this.app.setPrice(this.ps) },
  },
  methods: {
    tok,
    pick(e) { this.file = e.target.files[0] || null },
    async go() {
      if (!this.file && !this.video) return this.app.toast('Сначала выберите фото')
      if (this.file && this.file.size > 10 * 1024 * 1024) return this.app.toast('Фото больше 10 МБ')
      const ps = this.ps
      this.app.closeSheet()
      await this.app.start({ photoset: ps, file: this.file, prompt: this.extra.trim() })
    },
  },
}
</script>
