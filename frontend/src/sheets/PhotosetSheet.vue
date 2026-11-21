<template>
  <video v-if="videoExample" :src="ps.cover_url" class="pset-img" playsinline controls preload="metadata" />
  <ArtCanvas v-else-if="ps" :src="ps.cover_url" :seed="ps.seed" :palette="ps.palette" :w="320" :h="400" cls="pset-img" />
  <h3 id="sheetTitle">{{ ps ? ps.name : '' }}</h3>
  <p class="pset-cost">Списание {{ tok(price) }}</p>
  <p class="lead">{{ ps ? ps.description : '' }}</p>
  <button type="button" class="bigbtn shine" @click="use"><span class="lbl">Использовать</span></button>
</template>

<script>
import { useApp } from '../stores/app'
import ArtCanvas from '../components/ArtCanvas.vue'
import { tok } from '../services/format'
import { tg } from '../services/telegram'

export default {
  name: 'PhotosetSheet',
  components: { ArtCanvas },
  props: { id: { type: Number, required: true } },
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
    use() { tg.haptic('selection'); this.app.openSheet('psetUpload', { id: this.id }) },
  },
}
</script>
