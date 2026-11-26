<template>
  <div class="audio-input">
    <div class="music-field">
      <span>{{ label }}</span>
      <div class="audio-file-control">
        <button type="button" class="pill-btn" :aria-label="label + ' · выбрать файл'" @click="picker.click()">
          {{ modelValue ? 'Заменить файл' : 'Выбрать файл' }}
        </button>
        <span v-if="modelValue" :title="modelValue.name">{{ modelValue.name }}</span>
        <input ref="picker" type="file" hidden accept="audio/*,.mp3,.wav,.m4a,.webm" @change="pick">
      </div>
    </div>
    <div v-if="recordable || modelValue" class="audio-input-actions">
      <button v-if="recordable" type="button" class="pill-btn" @click="recording ? stop() : start()">{{ recording ? 'Остановить запись' : 'Записать с микрофона' }}</button>
      <button v-if="modelValue" type="button" class="pill-btn" @click="clear">Убрать запись</button>
    </div>
    <audio v-if="preview" :src="preview" controls preload="metadata" :aria-label="label" />
    <p v-if="error" class="music-error" role="alert">{{ error }}</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({ modelValue: File, label: String, recordable: Boolean })
const emit = defineEmits(['update:modelValue'])
const picker = ref(null)
const recording = ref(false)
const preview = ref('')
const error = ref('')
let recorder
let stream
let timer
let disposed = false

watch(() => props.modelValue, (file) => {
  if (preview.value) URL.revokeObjectURL(preview.value)
  preview.value = file ? URL.createObjectURL(file) : ''
}, { immediate: true })

function choose(file) {
  if (file.size > 20 * 1024 * 1024) {
    error.value = 'Максимальный размер аудиофайла — 20 МБ.'
    return
  }
  error.value = ''
  emit('update:modelValue', file)
}

function pick(event) {
  const file = event.target.files[0]
  if (file) choose(file)
  event.target.value = ''
}

function clear() {
  error.value = ''
  emit('update:modelValue', null)
  if (picker.value) picker.value.value = ''
}

async function start() {
  if (stream || recording.value) return
  error.value = ''
  try {
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) throw new Error('Microphone unavailable')
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    if (disposed) { stream.getTracks().forEach((track) => track.stop()); return }
    recorder = new MediaRecorder(stream)
    const chunks = []
    recorder.ondataavailable = ({ data }) => { if (data.size) chunks.push(data) }
    recorder.onstop = () => {
      const type = recorder.mimeType || 'audio/webm'
      const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm'
      if (!disposed && chunks.length) choose(new File(chunks, 'voice.' + ext, { type }))
      release()
    }
    recorder.start()
    recording.value = true
    timer = setTimeout(stop, 120_000)
  } catch {
    release()
    error.value = 'Микрофон недоступен. Загрузите готовую запись.'
  }
}

function stop() {
  if (recorder?.state === 'recording') recorder.stop()
}

function release() {
  clearTimeout(timer)
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
  recording.value = false
}

onBeforeUnmount(() => {
  disposed = true
  stop()
  release()
  if (preview.value) URL.revokeObjectURL(preview.value)
})
</script>
