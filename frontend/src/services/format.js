export const plural = (n, a, b, c) => {
  const m10 = n % 10, m100 = n % 100
  return m10 === 1 && m100 !== 11 ? a : m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20) ? b : c
}
export const fmt = (n) => Number(n || 0).toLocaleString('ru-RU')
export const tok = (n) => `${fmt(n)} ${plural(n, 'токен', 'токена', 'токенов')}`
export const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
export const replay = (el, cls) => {
  if (!el) return
  el.classList.remove(cls)
  void el.offsetWidth
  el.classList.add(cls)
}
export const tokenColor = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim()
export const ago = (iso) => {
  const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  return d <= 0 ? 'сегодня' : d === 1 ? 'вчера' : `${d} ${plural(d, 'день', 'дня', 'дней')} назад`
}
export const isVideoUrl = (url) => /\.(mp4|mov|webm)(\?|$)/i.test(url || '')
export const isAudioUrl = (url) => /\.(mp3|wav|ogg|m4a|aac|flac)(\?|$)/i.test(url || '')
/** Files the user can attach: type and size are checked before anything is uploaded. */
export const checkImage = (file, maxMb = 10) => {
  if (!file) return 'Файл не выбран'
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return 'Подойдёт JPG, PNG или WebP'
  if (file.size > maxMb * 1024 * 1024) return `Фото больше ${maxMb} МБ`
  return null
}
/**
 * What a token pack gives, as the bot's shop row says in description ({"photo": 15, "video": 2, "emoji": "💫"}):
 * "💫 15 изображений" instead of "500 токенов". A row without that JSON falls back to tokens.
 */
export const packHasCounts = (p) => p?.photo != null
export const packPhotos = (p) => packHasCounts(p)
  ? `${p.emoji ? p.emoji + ' ' : ''}${fmt(p.photo)} ${plural(p.photo, 'изображение', 'изображения', 'изображений')}`
  : tok(p?.tokens || 0)
/** "2 видео", or '' when the pack has no videos (then no plaque is shown). */
export const packVideos = (p) => (p?.video > 0 ? `${fmt(p.video)} видео` : '')

