// Tiny event bus for fire-and-forget visual effects (sparks, confetti).
const target = new EventTarget()
export const bus = {
  emit: (name, detail) => target.dispatchEvent(new CustomEvent(name, { detail })),
  on: (name, cb) => {
    const h = (e) => cb(e.detail)
    target.addEventListener(name, h)
    return () => target.removeEventListener(name, h)
  },
}
export const burstFrom = (el, n = 12, spread = 70) => {
  if (!el) return
  const r = el.getBoundingClientRect()
  bus.emit('burst', { x: r.left + r.width / 2, y: r.top + r.height / 2, n, spread })
}
export const confetti = () => bus.emit('burst', { x: innerWidth / 2, y: innerHeight * 0.35, n: 36, spread: 180, rich: true })
