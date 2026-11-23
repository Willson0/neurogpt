<template>
  <section class="adm-sec">
    <form class="adm-bar" @submit.prevent="load()">
      <label class="adm-f grow">Найти запись<input v-model.trim="query" type="search" placeholder="Модель, код, ID задачи или генерации" maxlength="128" @search="load()"></label>
      <label class="adm-f">Провайдер<select v-model="provider" @change="load()"><option value="">Все</option><option v-for="(label, id) in providers" :key="id" :value="id">{{ label }}</option></select></label>
      <label class="adm-f">Результат<select v-model="outcome" @change="load()"><option value="">Все события</option><option value="error">Ошибки</option><option value="ok">Ответы без ошибок</option></select></label>
      <button type="submit" class="adm-btn soft">Найти</button>
    </form>

    <p v-if="loading && !items.length" class="spinner-row" role="status">Загружаем журнал…</p>
    <div v-else-if="failed" class="empty pad"><h3>Журнал недоступен</h3><button class="pill-btn" type="button" @click="load()">Повторить</button></div>
    <p v-else-if="!items.length" class="adm-note">По выбранным условиям событий пока нет. Журнал хранится 14 дней.</p>

    <details data-admin-search-ignore v-for="event in items" :key="event.id" class="adm-item glass provider-event">
      <summary>
        <span :class="['adm-pill', event.outcome === 'error' ? 'bad' : 'ok']">{{ event.outcome === 'error' ? 'Ошибка' : 'Ответ' }}</span>
        <span class="event-title"><b>{{ providers[event.provider] }} · {{ operations[event.operation] || event.operation }}</b><small>{{ [event.model, event.feature, reasons[event.reason]].filter(Boolean).join(' · ') }}</small></span>
        <time :datetime="event.created_at">{{ when(event.created_at) }}</time>
      </summary>
      <dl class="event-details">
        <template v-if="event.reason"><dt>Причина</dt><dd>{{ reasons[event.reason] || event.reason }}</dd></template>
        <template v-if="event.host"><dt>Шлюз</dt><dd>{{ event.host }}</dd></template>
        <template v-if="event.http_status"><dt>HTTP</dt><dd>{{ event.http_status }}</dd></template>
        <template v-if="event.provider_code"><dt>Код API</dt><dd>{{ event.provider_code }}</dd></template>
        <template v-if="event.generation_id"><dt>Генерация</dt><dd>#{{ event.generation_id }}</dd></template>
        <template v-if="event.task_id"><dt>Задача провайдера</dt><dd>{{ event.task_id }}</dd></template>
        <template v-if="event.request_id"><dt>Запрос провайдера</dt><dd>{{ event.request_id }}</dd></template>
        <template v-if="event.duration_ms !== null"><dt>Время ответа</dt><dd>{{ event.duration_ms }} мс</dd></template>
      </dl>
    </details>

    <button v-if="next" class="adm-btn soft" type="button" :disabled="loading" @click="load(next)">{{ loading ? 'Загружаем…' : 'Показать ещё' }}</button>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { adminApi } from '../services/api'
import { when } from './shared'

const props = defineProps({ initialQuery: { type: String, default: '' } })
const providers = { kie: 'Kie.ai', openai: 'OpenAI-совместимый API', replicate: 'Replicate' }
const operations = { chat: 'Текстовый запрос', chat_result: 'Текстовый результат', image: 'Генерация изображения', image_edit: 'Обработка изображения', submit: 'Создание задачи', poll: 'Проверка задачи', upload_image: 'Загрузка фото', upload_media: 'Загрузка референса', download: 'Получение результата', generation_failed: 'Ошибка генерации' }
const reasons = { authorization: 'Проверьте ключ и доступ к модели', credits: 'Недостаточно кредитов у провайдера', rate_limit: 'Превышен лимит запросов', moderation: 'Запрос отклонён модерацией', size: 'Файл или запрос слишком большой', not_found: 'Задача или ресурс не найдены', timeout: 'Провайдер не ответил вовремя', connection: 'Ошибка соединения с провайдером', input: 'Провайдер отклонил параметры', result: 'Пустой или некорректный результат', upstream: 'Ошибка на стороне провайдера', unknown: 'Причина не определена' }
const query = ref(props.initialQuery)
const provider = ref('')
const outcome = ref(props.initialQuery ? '' : 'error')
const items = ref([])
const next = ref(null)
const loading = ref(false)
const failed = ref(false)
let sequence = 0

async function load(page = 1) {
  const request = ++sequence
  loading.value = true
  failed.value = false
  if (page === 1) { items.value = []; next.value = null }
  try {
    const result = await adminApi.list('provider-events', { q: query.value, provider: provider.value, outcome: outcome.value, page })
    if (request !== sequence) return
    items.value = page === 1 ? result.data : [...items.value, ...result.data]
    next.value = result.next_page
  } catch {
    if (request === sequence) failed.value = true
  } finally {
    if (request === sequence) loading.value = false
  }
}
onMounted(() => load())
</script>

<style scoped>
.provider-event { padding: 10px 12px; }
.provider-event summary { cursor: pointer; display: flex; align-items: center; gap: 10px; }
.provider-event summary time { color: var(--color-muted); font-size: var(--text-1); text-align: right; }
.event-title { display: grid; flex: 1; min-width: 0; overflow-wrap: anywhere; }
.event-title small { color: var(--color-muted); }
.event-details { display: grid; grid-template-columns: minmax(85px, max-content) minmax(0, 1fr); gap: 6px 10px; margin: 12px 0 0; }
.event-details dt { color: var(--color-muted); }
.event-details dd { margin: 0; overflow-wrap: anywhere; }
</style>
