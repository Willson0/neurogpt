<template>
  <video v-if="videoExample" :src="ps.cover_url" class="pset-img" playsinline controls preload="metadata" />
  <ArtCanvas v-else-if="ps" :src="ps.cover_url" :seed="ps.seed" :palette="ps.palette" :w="320" :h="400" cls="pset-img" style="aspect-ratio:16 / 10" />
  <h3 id="sheetTitle">{{ ps ? ps.name : '' }}</h3>
  <p class="pset-cost">Списание {{ tok(price) }}</p>
  <label class="pill-btn psetfile">
    <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" @change="pick">
    <svg class="ic-s" aria-hidden="true"><use href="#i-clip" /></svg><span>{{ file ? file.name : 'Выбрать фото' }}</span>
  </label>
  <div v-if="kind === 'video'" class="filters">
    <label>Модель <select :value="model?.id" @change="selection = $event.target.value; duration = null; format = null"><option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }}</option></select></label>
    <label>Длительность <select v-model="duration"><option :value="null">По умолчанию</option><option v-for="d in model?.durations" :key="d.seconds" :value="d.seconds">{{ d.seconds }} сек.</option></select></label>
    <label>Формат <select v-model="format"><option :value="null">По умолчанию</option><option v-for="ratio in model?.formats" :key="ratio">{{ ratio }}</option></select></label>
  </div>
  <div class="prompt glass">
    <label for="psetExtra" class="visually-hidden">Дополнительный промпт</label>
    <textarea id="psetExtra" v-model="extra" rows="2" maxlength="500" placeholder="Что добавить: цвет, фон, настроение" />
  </div>
  <button type="button" class="bigbtn shine" :disabled="app.busy || !model" @click="go"><span class="lbl">Отправить на генерацию</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import ArtCanvas from '../components/ArtCanvas.vue'
import { tok } from '../services/format'

export default {
  name: 'PhotosetUploadSheet',
  components: { ArtCanvas },
  props: { id: { type: Number, required: true } },
  data: () => ({ file: null, extra: '', selection: '', duration: null, format: null }),
  computed: {
    app() { return useApp() },
    videoExample() { return this.ps?.kind === 'video' && /\.(mp4|webm|mov)(?:$|[?#])/i.test(this.ps.cover_url || '') },
    kind() { return this.ps?.kind || 'image' },
    models() { return this.app.models[this.kind] },
    model() { return this.models.find(m => m.id === (this.selection || this.app.modelSel[this.kind])) || this.models[0] },
    ps() { return this.app.photosets.find((p) => p.id === this.id) },
    price() { return this.model?.durations?.find(d => d.seconds === this.duration)?.price ?? this.model?.durations?.[0]?.price ?? this.model?.price ?? 0 },
  },
  methods: {
    tok,
    pick(e) { this.file = e.target.files[0] || null },
    async go() {
      if (!this.file) return this.app.toast('Сначала выберите фото')
      if (this.file.size > 10 * 1024 * 1024) return this.app.toast('Фото больше 10 МБ')
      const ps = this.ps
      this.app.closeSheet()
      await this.app.start({ photoset: ps, file: this.file, prompt: this.extra.trim(), model: this.model.id, duration: this.duration, format: this.format })
    },
  },
}
</script>
