<template>
  <div v-if="rendered" class="scrim" :class="{ open }" @click="app.closeSheet()" />
  <div v-if="rendered" ref="sheet" :class="['sheet', open ? 'open' : '', dragging ? 'dragging' : '']" role="dialog" aria-modal="true" aria-labelledby="sheetTitle"
       :style="{ '--drag': drag + 'px' }"
       @pointerdown="down" @pointermove="move" @pointerup="end" @pointercancel="end">
    <div class="grabber" aria-hidden="true" />
    <component :is="registry[rendered.name]" v-bind="rendered.props" />
  </div>
</template>

<script>
import { markRaw } from 'vue'
import { useApp } from '../stores/app'
import ModelsSheet from './ModelsSheet.vue'
import MoreSheet from './MoreSheet.vue'
import NeedSheet from './NeedSheet.vue'
import ResultMoreSheet from './ResultMoreSheet.vue'
import InviteSheet from './InviteSheet.vue'
import FirstSheet from './FirstSheet.vue'
import FilterSheet from './FilterSheet.vue'
import PhotosetSheet from './PhotosetSheet.vue'
import PhotosetUploadSheet from './PhotosetUploadSheet.vue'
import ToolSheet from './ToolSheet.vue'

export default {
  name: 'SheetHost',
  data: () => ({ rendered: null, open: false, drag: 0, dragging: false, y0: null }),
  computed: {
    app() { return useApp() },
    registry: () => ({
      models: markRaw(ModelsSheet), more: markRaw(MoreSheet), need: markRaw(NeedSheet), resmore: markRaw(ResultMoreSheet),
      invite: markRaw(InviteSheet), first: markRaw(FirstSheet), filter: markRaw(FilterSheet),
      photoset: markRaw(PhotosetSheet), psetUpload: markRaw(PhotosetUploadSheet), tool: markRaw(ToolSheet),
    }),
  },
  watch: {
    'app.sheet'(s) {
      clearTimeout(this.timer)
      if (s) {
        this.rendered = s
        this.drag = 0
        requestAnimationFrame(() => requestAnimationFrame(() => { this.open = true }))
      } else {
        this.open = false
        this.timer = setTimeout(() => { if (!this.app.sheet) this.rendered = null }, 360)
      }
    },
  },
  mounted() { this.onKey = (e) => e.key === 'Escape' && this.app.closeSheet(); document.addEventListener('keydown', this.onKey) },
  beforeUnmount() { document.removeEventListener('keydown', this.onKey) },
  methods: {
    down(e) {
      const sheet = this.$refs.sheet
      if (sheet.scrollTop > 0 || e.target.closest('button, input, textarea, label, summary')) return
      this.y0 = e.clientY; this.dragging = true
      sheet.setPointerCapture(e.pointerId)
    },
    move(e) { if (this.y0 !== null) this.drag = Math.max(0, e.clientY - this.y0) },
    end() {
      if (this.y0 === null) return
      this.y0 = null; this.dragging = false
      if (this.drag > 90) this.app.closeSheet()
      else this.drag = 0
    },
  },
}
</script>
