/**
 * v-drag-scroll: makes a horizontal strip comfortable with a mouse.
 *  - press and drag to scroll, with a short glide after release
 *  - the vertical wheel scrolls the strip sideways until it reaches its edge
 *  - a click that ends a drag does not open the card under the cursor
 * Touch keeps the native scroll.
 */
const state = new WeakMap()

export const dragScroll = {
  mounted(el) {
    const s = { down: false, moved: false, x0: 0, left0: 0, v: 0, lastX: 0, lastT: 0, raf: 0 }

    const stop = () => cancelAnimationFrame(s.raf)
    const glide = () => {
      if (Math.abs(s.v) < 0.02) { el.classList.remove('dragging'); return }
      el.scrollLeft -= s.v * 16
      s.v *= 0.94
      s.raf = requestAnimationFrame(glide)
    }

    s.onDown = (e) => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return
      stop()
      s.down = true; s.moved = false; s.x0 = e.clientX; s.left0 = el.scrollLeft
      s.lastX = e.clientX; s.lastT = performance.now(); s.v = 0
    }
    s.onMove = (e) => {
      if (!s.down) return
      const dx = e.clientX - s.x0
      if (!s.moved && Math.abs(dx) < 5) return
      if (!s.moved) { s.moved = true; el.classList.add('dragging') }
      el.scrollLeft = s.left0 - dx
      const now = performance.now()
      if (now > s.lastT) s.v = (e.clientX - s.lastX) / (now - s.lastT)
      s.lastX = e.clientX; s.lastT = now
      e.preventDefault()
    }
    s.onUp = () => {
      if (!s.down) return
      s.down = false
      if (s.moved) s.raf = requestAnimationFrame(glide)
    }
    s.onClick = (e) => {
      if (s.moved) { e.preventDefault(); e.stopPropagation(); s.moved = false }
    }
    s.onWheel = (e) => {
      if (Math.abs(e.deltaX) > Math.abs(e.deltaY) || e.ctrlKey) return
      const max = el.scrollWidth - el.clientWidth
      if (max <= 0) return
      const atEdge = e.deltaY < 0 ? el.scrollLeft <= 0 : el.scrollLeft >= max - 1
      if (atEdge) return
      el.scrollLeft += e.deltaY
      e.preventDefault()
    }
    s.onDragStart = (e) => e.preventDefault()

    el.addEventListener('pointerdown', s.onDown)
    window.addEventListener('pointermove', s.onMove)
    window.addEventListener('pointerup', s.onUp)
    el.addEventListener('click', s.onClick, true)
    el.addEventListener('wheel', s.onWheel, { passive: false })
    el.addEventListener('dragstart', s.onDragStart)
    state.set(el, s)
  },
  unmounted(el) {
    const s = state.get(el)
    if (!s) return
    cancelAnimationFrame(s.raf)
    el.removeEventListener('pointerdown', s.onDown)
    window.removeEventListener('pointermove', s.onMove)
    window.removeEventListener('pointerup', s.onUp)
    el.removeEventListener('click', s.onClick, true)
    el.removeEventListener('wheel', s.onWheel)
    el.removeEventListener('dragstart', s.onDragStart)
  },
}
