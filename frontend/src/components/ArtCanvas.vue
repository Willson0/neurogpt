<template>
  <img v-if="src" :src="src" alt="" :class="cls" loading="lazy">
  <canvas v-else ref="cv" :class="cls" width="10" height="10" aria-hidden="true" />
</template>

<script>
import { paintArt } from '../services/art'

// A photoset cover: the uploaded image when there is one, generative art otherwise.
export default {
  name: 'ArtCanvas',
  props: {
    src: { type: String, default: null },
    seed: { type: Number, default: 1 },
    palette: { type: Number, default: 0 },
    w: { type: Number, default: 180 },
    h: { type: Number, default: 226 },
    cls: { type: String, default: '' },
  },
  mounted() { this.paint() },
  watch: { seed() { this.paint() }, palette() { this.paint() } },
  methods: {
    paint() {
      if (!this.src && this.$refs.cv) paintArt(this.$refs.cv, this.seed, this.palette, this.w, this.h)
    },
  },
}
</script>
