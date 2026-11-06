<template>
  <ArtCanvas v-if="ps" :src="ps.cover_url" :seed="ps.seed" :palette="ps.palette" :w="320" :h="400" cls="pset-img" style="aspect-ratio:16 / 10" />
  <h3 id="sheetTitle">{{ ps ? ps.name : '' }}</h3>
  <p class="pset-cost">Списание {{ tok(price) }}</p>
  <label class="pill-btn psetfile">
    <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" @change="pick">
    <svg class="ic-s" aria-hidden="true"><use href="#i-clip" /></svg><span>{{ file ? file.name : 'Выбрать фото' }}</span>
  </label>
  <div class="prompt glass">
    <label for="psetExtra" class="visually-hidden">Дополнительный промпт</label>
    <textarea id="psetExtra" v-model="extra" rows="2" maxlength="500" placeholder="Что добавить: цвет, фон, настроение" />
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
    price() { return (this.app.models.image.find((m) => m.id === this.app.modelSel.image) || this.app.models.image[0] || {}).price || 0 },
  },
  methods: {
    tok,
    pick(e) { this.file = e.target.files[0] || null },
    async go() {
      if (!this.file) return this.app.toast('Сначала выберите фото')
      if (this.file.size > 10 * 1024 * 1024) return this.app.toast('Фото больше 10 МБ')
      const ps = this.ps
      this.app.closeSheet()
      await this.app.start({ photoset: ps, file: this.file, prompt: this.extra.trim() })
    },
  },
}
</script>
