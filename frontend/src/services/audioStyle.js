const SAMPLE_RATE = 22050
const FRAME_SIZE = 220

export async function describeAudio(file) {
  const OfflineContext = window.OfflineAudioContext || window.webkitOfflineAudioContext
  if (!OfflineContext) throw new Error('Audio analysis is unavailable')
  const context = new OfflineContext(1, 1, SAMPLE_RATE)
  const audio = await context.decodeAudioData(await file.arrayBuffer())
  const samples = audio.getChannelData(0)
  const frames = Math.floor(Math.min(audio.duration, 45) * SAMPLE_RATE / FRAME_SIZE)
  const start = Math.floor((samples.length - frames * FRAME_SIZE) / 2)
  if (frames < 400) throw new Error('Audio is too short')

  const levels = []
  const attacks = []
  let crossings = 0
  let previousLevel = 0
  for (let frame = 0; frame < frames; frame++) {
    const offset = start + frame * FRAME_SIZE
    let power = 0
    let edges = 0
    for (let index = offset; index < offset + FRAME_SIZE; index++) {
      const sample = samples[index]
      power += sample * sample
      if (index > offset && (sample > 0) !== (samples[index - 1] > 0)) edges++
    }
    const rms = Math.sqrt(power / FRAME_SIZE)
    const level = Math.log1p(100 * rms)
    levels.push(level)
    attacks.push(frame ? Math.max(0, level - previousLevel) : 0)
    if (rms > 0.01) crossings += edges
    previousLevel = level
  }

  const active = levels.filter(level => level > Math.log(2)).sort((a, b) => a - b)
  if (active.length < 100) throw new Error('Audio is mostly silent')
  const spread = active[Math.floor(active.length * .9)] - active[Math.floor(active.length * .1)]
  const dynamics = spread > 1.25 ? 'заметные перепады громкости' : 'ровная динамика'
  const brightness = crossings / (active.length * FRAME_SIZE)
  const texture = brightness > .14 ? 'яркое звучание' : brightness < .045 ? 'мягкое звучание' : ''
  const bpm = estimateTempo(attacks)
  return [bpm ? 'около ' + bpm + ' BPM' : '', dynamics, texture].filter(Boolean).join(', ')
}

function estimateTempo(attacks) {
  const secondsPerFrame = FRAME_SIZE / SAMPLE_RATE
  const minLag = Math.ceil(60 / (190 * secondsPerFrame))
  const maxLag = Math.floor(60 / (55 * secondsPerFrame))
  const mean = attacks.reduce((sum, value) => sum + value, 0) / attacks.length
  const centered = attacks.map(value => value - mean)
  const power = centered.reduce((sum, value) => sum + value * value, 0)
  if (power < 0.1) return null

  const scores = []
  for (let lag = minLag; lag <= maxLag; lag++) {
    let correlation = 0
    for (let i = lag; i < centered.length; i++) correlation += centered[i] * centered[i - lag]
    scores.push({ lag, value: correlation / power })
  }
  const strongest = Math.max(...scores.map(score => score.value))
  if (strongest < .27) return null
  const peak = scores.find(({ value }, index) => value >= strongest * .9
    && value >= (scores[index - 1]?.value ?? 0) && value >= (scores[index + 1]?.value ?? 0))
  return peak ? Math.round(60 / (peak.lag * secondsPerFrame)) : null
}
