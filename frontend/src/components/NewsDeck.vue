<template>
  <section v-if="cards.length" ref="deck" :class="['deck', paused ? 'paused' : '']" aria-label="Акции и новости" aria-roledescription="карусель"
           @pointerenter="hold = true" @pointerleave="hold = false" @focusin="hold = true" @focusout="hold = false">
    <button v-for="(c, ci) in cards" :key="c.id" type="button"
            :class="['card', 'c-' + c.style, flung && flung.ci === ci ? (flung.dir < 0 ? 'fling' : 'fling-r') : '', flung && flung.ci === ci && flung.park ? 'park' : '', settle === ci ? 'settle' : '', dragCi === ci ? 'drag' : '']"
            :style="dragCi === ci ? { transform: `translateX(${dx}px) rotate(${dx / 18}deg)` } : null"
            :data-pos="order.indexOf(ci)" :aria-label="c.title"
            @pointerdown="down($event, ci)" @pointermove="move($event, ci)" @pointerup="up($event, ci)" @pointercancel="up($event, ci)"
            @click.capture="click($event)">
      <span class="ct"><b>{{ c.title }}</b><span>{{ c.subtitle }}</span></span>
      <Sticker v-if="c.sticker" :name="c.sticker" :size="66" />
    </button>
    <div v-if="cards.length > 1" class="deck-bar">
      <div class="segs" :style="{ gridTemplateColumns: `repeat(${cards.length}, minmax(0, 1fr))` }">
        <button v-for="(c, k) in cards" :key="c.id" type="button"
                :class="['seg-i', k === order[0] ? 'on' : '', k < order[0] ? 'done' : '']" :aria-label="'Слайд ' + (k + 1)" @click="go(k)">
          <i @animationend="autoNext(k)" />
        </button>
      </div>
      <button type="button" class="mini" :aria-label="userPaused ? 'Запустить карусель' : 'Остановить карусель'" @click="userPaused = !userPaused">
        <svg aria-hidden="true"><use :href="userPaused ? '#i-play' : '#i-pause'" /></svg>
      </button>
    </div>
  </section>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from './Sticker.vue'
import { reduceMotion } from '../services/format'
import { tg } from '../services/telegram'

export default {
  name: 'NewsDeck',
  components: { Sticker },
  data: () => ({ order: [], userPaused: false, hold: false, flung: null, settle: -1, dragCi: -1, dx: 0, x0: null, moved: false }),
  computed: {
    app() { return useApp() },
    cards() { return this.app.news.slice(0, 3) },
    paused() { return this.userPaused || this.hold || reduceMotion() },
  },
  watch: {
    cards: { immediate: true, handler(c) { this.order = c.map((_, i) => i) } },
  },
  methods: {
    next(dir = -1) {
      if (this.order.length < 2) return
      const front = this.order[0]
      this.flung = { ci: front, dir, park: false }
      this.order = [...this.order.slice(1), front]
      setTimeout(() => {
        // the flung card is off-screen: park it at the back without animating the return
        this.flung = { ci: front, dir, park: true }
        requestAnimationFrame(() => requestAnimationFrame(() => {
          this.flung = null
          if (!reduceMotion()) { this.settle = front; setTimeout(() => { this.settle = -1 }, 440) }
        }))
      }, reduceMotion() ? 0 : 380)
    },
    go(i) {
      const o = [...this.order]
      while (o[0] !== i) o.push(o.shift())
      this.order = o
    },
    autoNext(k) { if (!this.paused && k === this.order[0]) this.next() },
    down(e, ci) {
      if (this.order[0] !== ci) return
      this.x0 = e.clientX; this.moved = false; this.dragCi = ci; this.dx = 0
      e.currentTarget.setPointerCapture(e.pointerId)
    },
    move(e) {
      if (this.x0 === null) return
      this.dx = e.clientX - this.x0
      if (Math.abs(this.dx) > 6) this.moved = true
    },
    up(e) {
      if (this.x0 === null) return
      const d = e.clientX - this.x0
      this.x0 = null; this.dragCi = -1; this.dx = 0
      if (Math.abs(d) > 60) this.next(d < 0 ? -1 : 1)
    },
    click(e) {
      if (this.moved) { e.stopPropagation(); e.preventDefault(); this.moved = false; return }
      const ci = [...this.$el.querySelectorAll('.card')].indexOf(e.target.closest('.card'))
      const c = this.cards[ci]
      if (!c || this.order[0] !== ci) return
      tg.haptic('light')
      const a = this.app
      if (c.action === 'topup') a.push('topup')
      else if (c.action === 'plans') a.push('plans')
      else if (c.action === 'models') a.openSheet('models')
      else if (c.action === 'invite') a.openSheet('invite')
    },
  },
}
</script>

<style>
.card.park { transition: none !important; }
</style>
