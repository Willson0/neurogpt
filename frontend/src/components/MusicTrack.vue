<template>
  <article class="music-track" :id="'music-track-' + generation.id + '-' + index">
    <div class="music-track-head">
      <img v-if="cover?.result_url" class="music-art" :src="cover.result_url" alt="Обложка трека" loading="lazy">
      <span v-else class="music-art music-art-fallback"><svg class="ic" aria-hidden="true"><use href="#i-music" /></svg></span>
      <div class="music-track-title">
        <h3>{{ track.title || generation.music?.name || generation.prompt || 'Без названия' }}</h3>
        <p>{{ version }}<template v-if="track.duration"> · {{ duration }}</template></p>
      </div>
    </div>
    <audio controls preload="none" :src="track.url" :aria-label="track.title || generation.prompt || version" @play="pauseOthers" />
    <div class="music-track-actions">
      <button type="button" class="pill-btn" :disabled="downloading" @click="download"><svg class="ic-s" aria-hidden="true"><use href="#i-down" /></svg>{{ downloading ? 'Готовим файл…' : 'Скачать' }}</button>
      <button type="button" class="pill-btn" :disabled="sending" @click="send">{{ sending ? 'Отправляем…' : 'В чат' }}</button>
      <button type="button" class="pill-btn" @click="edit('cover')">{{ cover?.result_url ? 'Новая обложка' : 'Создать обложку' }} · {{ tok(app.musicPrices.cover ?? 0) }}</button>
      <a v-if="cover?.result_url" :href="cover.result_url" class="pill-btn" target="_blank" rel="noopener">Обложка</a>
    </div>
    <details class="music-more">
      <summary>Обработка трека</summary>
      <div class="music-track-actions">
        <button v-if="track.editable && !['wav', 'stems'].includes(generation.music?.operation)" type="button" class="pill-btn" @click="edit('cover_audio')">Кавер на этот трек</button>
        <button v-if="track.editable" type="button" class="pill-btn" @click="edit('extend')">Продолжить</button>
        <button v-if="track.editable && (!track.duration || track.duration >= 20)" type="button" class="pill-btn" @click="edit('replace')">Заменить фрагмент</button>
        <button v-if="track.editable && ['generate', 'extend'].includes(generation.music?.operation)" type="button" class="pill-btn" @click="edit('persona')">Сохранить стиль</button>
        <button v-if="track.editable" type="button" class="pill-btn" @click="edit('wav')">Экспорт WAV</button>
        <button v-if="track.editable" type="button" class="pill-btn" @click="edit('stems')">Разделить дорожки</button>
      </div>
    </details>
    <details v-if="track.lyrics" class="music-more"><summary>Текст песни</summary><p class="music-lyrics">{{ track.lyrics }}</p></details>
    <p v-if="error" class="music-error" role="alert">{{ error }}</p>
  </article>
</template>

<script setup>
import { computed, ref } from 'vue'
import { api } from '../services/api'
import { tg } from '../services/telegram'
import { tok } from '../services/format'
import { useApp } from '../stores/app'

const props = defineProps({ generation: Object, track: Object, index: Number, cover: Object })
const emit = defineEmits(['edit', 'play'])
const app = useApp()
const sending = ref(false)
const downloading = ref(false)
const error = ref('')
const duration = computed(() => {
  const seconds = Math.round(props.track.duration)
  return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0')
})
const version = computed(() => 'Вариант ' + (props.index + 1) + (props.generation.music?.model ? ' · ' + props.generation.music.model.replaceAll('_', ' ') : ''))

function pauseOthers(event) { emit('play', event.currentTarget) }
function edit(operation) { emit('edit', { operation, generation: props.generation, track: props.track, index: props.index }) }

async function download() {
  error.value = ''
  const ext = new URL(props.track.url, location.origin).pathname.split('.').pop() || 'mp3'
  const filename = 'neurogpt-' + props.generation.id + '-' + (props.index + 1) + '.' + ext
  // Telegram's native download works with the stored media URL and shows its own save dialog.
  if (tg.canDownload && props.track.download_url) {
    tg.download(props.track.download_url, filename)
    return
  }
  downloading.value = true
  try {
    const url = await api.musicDownload(props.generation.id, props.index)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.append(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 30_000)
  } catch {
    error.value = 'Не удалось скачать файл. Попробуйте ещё раз.'
  } finally {
    downloading.value = false
  }
}

async function send() {
  sending.value = true
  error.value = ''
  try {
    await api.sendTrack(props.generation.id, props.index)
    app.toast('Трек отправлен в чат')
  } catch (e) {
    error.value = e.status === 502 ? e.body.message : 'Отправка не удалась. Попробуйте ещё раз.'
  } finally {
    sending.value = false
  }
}
</script>
