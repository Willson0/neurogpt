import { tokenColor } from './format'

const PALS = [1, 2, 3, 4].map((i) => ['a', 'b', 'c', 'd'].map((s) => `--art-${i}${s}`))

function rng(seed) {
  let s = seed >>> 0 || 1
  return () => (s = (s * 1664525 + 1013904223) >>> 0) / 4294967296
}

/** Generative cover used when a photoset has no uploaded cover image. */
export function paintArt(cv, seed, pal, w, h) {
  const W = (cv.width = w), H = (cv.height = h), x = cv.getContext('2d'), r = rng(seed)
  const P = PALS[pal % 4].map(tokenColor)
  const g = x.createLinearGradient(0, 0, W * r(), H)
  g.addColorStop(0, P[0]); g.addColorStop(1, P[1]); x.fillStyle = g; x.fillRect(0, 0, W, H)
  x.globalCompositeOperation = 'screen'
  for (let i = 0; i < 5; i++) {
    const cx = r() * W, cy = r() * H, rad = (0.25 + r() * 0.5) * Math.max(W, H)
    const rg = x.createRadialGradient(cx, cy, 0, cx, cy, rad)
    rg.addColorStop(0, P[1 + (i % 3)]); rg.addColorStop(1, 'transparent')
    x.globalAlpha = 0.55; x.fillStyle = rg; x.fillRect(0, 0, W, H)
  }
  x.globalCompositeOperation = 'source-over'; x.globalAlpha = 1
  const f1 = 1.5 + r() * 3, f2 = 1.5 + r() * 3, ph = r() * 6.28
  const field = (px, py) => Math.sin((px / W) * f1 * 6.28 + ph) * 1.4 + Math.cos((py / H) * f2 * 6.28 - ph) * 1.4 + Math.sin(((px + py) / W) * 3.1)
  x.lineCap = 'round'
  for (let i = 0; i < 700; i++) {
    let px = r() * W, py = r() * H
    x.beginPath(); x.moveTo(px, py)
    for (let s = 0; s < 18; s++) { const a = field(px, py); px += Math.cos(a) * 3.2; py += Math.sin(a) * 3.2; x.lineTo(px, py) }
    x.strokeStyle = P[2 + (i % 2)]; x.globalAlpha = 0.08 + r() * 0.22; x.lineWidth = 0.6 + r() * 2.2; x.stroke()
  }
  x.globalAlpha = 1
  const cx = W * (0.3 + r() * 0.4), cy = H * (0.25 + r() * 0.3), rad = Math.min(W, H) * (0.12 + r() * 0.1)
  const sg = x.createRadialGradient(cx, cy, 0, cx, cy, rad * 2.4)
  sg.addColorStop(0, P[3]); sg.addColorStop(0.35, P[2]); sg.addColorStop(1, 'transparent')
  x.globalCompositeOperation = 'screen'; x.fillStyle = sg; x.beginPath(); x.arc(cx, cy, rad * 2.4, 0, 6.29); x.fill()
  x.globalCompositeOperation = 'source-over'
  return cv
}
