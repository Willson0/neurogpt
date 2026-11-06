<template>
  <div ref="host" class="tgs" :style="{ width: size + 'px', height: size + 'px' }">
    <Sticker v-if="failed" name="coin" :size="Math.round(size * 0.8)" glow glow-color="--gold" />
  </div>
</template>

<script>
import lottie from 'lottie-web/build/player/lottie_light'
import Sticker from './Sticker.vue'
import { reduceMotion } from '../services/format'

const cache = {}

async function loadTgs(name) {
  if (cache[name]) return cache[name]
  const res = await fetch(`/tgs/${name}.tgs`)
  if (!res.ok) throw new Error('tgs')
  const stream = res.body.pipeThrough(new DecompressionStream('gzip'))
  cache[name] = JSON.parse(await new Response(stream).text())
  return cache[name]
}

// A .tgs file is a gzipped Lottie animation: the same format Telegram uses for animated stickers.
export default {
  name: 'TgsPlayer',
  components: { Sticker },
  props: { name: { type: String, default: 'token' }, size: { type: Number, default: 160 } },
  data: () => ({ failed: false, anim: null }),
  async mounted() {
    try {
      if (!('DecompressionStream' in window)) throw new Error('unsupported')
      const data = await loadTgs(this.name)
      this.anim = lottie.loadAnimation({
        container: this.$refs.host, renderer: 'svg', loop: !reduceMotion(), autoplay: !reduceMotion(),
        animationData: JSON.parse(JSON.stringify(data)),
      })
      if (reduceMotion()) this.anim.goToAndStop(0, true)
    } catch {
      this.failed = true
    }
  },
  beforeUnmount() { this.anim?.destroy() },
}
</script>
