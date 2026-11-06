<template>
  <span :class="['stk', 'a-' + animation, glow ? 'glow' : '']" :style="style">
    <img :src="`/stickers/${name}.webp`" alt="" :width="size" :height="size" loading="lazy">
    <template v-if="sparkle">
      <svg class="spk" aria-hidden="true"><use href="#i-spark4" /></svg>
      <svg class="spk" aria-hidden="true"><use href="#i-spark4" /></svg>
      <svg class="spk" aria-hidden="true"><use href="#i-spark4" /></svg>
    </template>
  </span>
</template>

<script>
const ANIM = {
  heart: 'beat', fire: 'flame', sparkles: 'twinkle', gift: 'wobble', moneybag: 'wobble', coin: 'spin', party: 'pop',
  art: 'paint', sleep: 'sleep', cry: 'shake', crown: 'float', gem: 'twinkle', wave: 'wave', magic: 'twinkle',
  robot: 'float', rocket: 'float', thinking: 'think', star: 'twinkle', money: 'wobble', hourglass: 'think',
  starstruck: 'beat', hundred: 'pop',
}

export default {
  name: 'Sticker',
  props: {
    name: { type: String, required: true },
    size: { type: Number, default: 64 },
    glow: Boolean,
    sparkle: Boolean,
    glowColor: { type: String, default: '' },
    anim: { type: String, default: '' },
  },
  computed: {
    animation() { return this.anim || ANIM[this.name] || 'float' },
    style() {
      return { '--s': this.size + 'px', ...(this.glowColor ? { '--g': `var(${this.glowColor})` } : {}) }
    },
  },
}
</script>
