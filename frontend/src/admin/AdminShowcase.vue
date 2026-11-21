<template>
  <section class="adm-sec">
    <div class="adm-bar">
      <div class="filters grow"><button v-for="(n, k) in tabs" :key="k" type="button" class="chip" :aria-pressed="String(tab === k)" @click="tab = k; cancel()">{{ n }}</button></div>
      <button type="button" class="adm-btn sm" @click="add">{{ tab === 'photosets' ? 'Добавить пресет' : 'Добавить карточку' }}</button>
    </div>
    <p class="adm-note">{{ tab === 'photosets' ? 'Готовые идеи для фото пользователя. Промпт уходит модели вместе с фотографией. Картинка-пример показывает людям, что получится.' : 'Карточки новостей на главном экране.' }}</p>
    <div v-if="tab === 'photosets' && missing.length && !loading" class="adm-card glass">
      <div class="adm-bar">
        <p class="grow" style="font-size:var(--text-2)">{{ bulk.on ? `Создаём примеры: ${bulk.done} из ${bulk.total}` : `Без картинки-примера: ${missing.length}` }}</p>
        <button v-if="!bulk.on" type="button" class="adm-btn sm" :disabled="!!gen.id" @click="generateAll">Сгенерировать для всех</button>
        <button v-else type="button" class="adm-btn soft sm" @click="bulk.stop = true">Остановить</button>
      </div>
    </div>
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <div v-else>
      <div v-for="it in items[tab]" :key="it.id || 'new'" :class="['adm-item', 'glass', it.is_active ? '' : 'off']">
        <button type="button" class="row" :aria-expanded="String(edit === it)" @click="toggle(it)">
          <video v-if="videoExample(it)" class="adm-thumb" :src="it.cover_url" muted playsinline preload="metadata" />
          <img v-else-if="tab === 'photosets' && it.cover_url" class="adm-thumb" :src="it.cover_url" alt="">
          <span v-else class="sq"><svg class="ic-s" aria-hidden="true"><use :href="tab === 'photosets' ? '#i-image' : '#i-story'" /></svg></span>
          <span class="t"><b>{{ it.name || it.title || 'Новая запись' }}</b><span>{{ tab === 'photosets' ? it.description : it.subtitle }}</span></span>
          <span class="end"><span v-if="!it.is_active" class="adm-pill bad">выкл</span><span v-if="it.is_trending" class="adm-pill hot">тренд</span></span>
        </button>
        <form v-if="edit === it" class="adm-edit" @submit.prevent="save">
          <div v-if="tab === 'photosets'" class="adm-grid">
            <label class="adm-f">Тип<select v-model="f.kind" :disabled="!!it.id"><option value="image">Фотосет</option><option value="video">Видеосет</option></select></label>
            <label class="adm-f">Название<input v-model.trim="f.name" required maxlength="120"></label>
            <label class="adm-f">Порядок<input v-model.number="f.sort" type="number" min="0"></label>
            <label class="adm-f">Цвета обложки без картинки<select v-model.number="f.palette"><option v-for="n in 5" :key="n" :value="n - 1">Палитра {{ n }}</option></select></label>
            <label class="adm-f">Узор обложки (число)<input v-model.number="f.seed" type="number" min="0" max="100000"></label>
            <label class="adm-f full">Описание для пользователя<textarea v-model.trim="f.description" maxlength="1000" rows="2" /></label>
            <label class="adm-f full">Промпт для модели<textarea v-model.trim="f.prompt" required maxlength="6000" rows="6" /></label>
            <div class="adm-f full">{{ f.kind === "video" ? "Видео-пример или обложка" : "Картинка-пример" }}
              <div class="adm-cover">
                <div class="adm-cover-img">
                  <video v-if="videoExample(f)" :src="f.cover_url" controls playsinline preload="metadata" style="width:100%" />
                  <img v-else-if="f.cover_url" :src="f.cover_url" alt="Текущий пример">
                  <span v-else>Пока заглушка</span>
                  <span v-if="gen.id === it.id" class="adm-cover-busy">Генерируем…</span>
                </div>
                <div class="adm-cover-side">
                  <template v-if="it.id">
                    <button type="button" class="adm-btn sm" :disabled="!!gen.id" @click="generate(it)">{{ f.cover_url ? 'Сгенерировать другой' : 'Сгенерировать пример' }}</button>
                    <label :class="['adm-btn', 'soft', 'sm', gen.id ? 'off' : '']" style="display:inline-flex;align-items:center;cursor:pointer">Сгенерировать по фото<input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" :disabled="!!gen.id" @change="generateFrom(it, $event)"></label>
                  </template>
                  <label class="adm-btn soft sm" style="display:inline-flex;align-items:center;cursor:pointer">Загрузить пример<input type="file" :accept="f.kind === 'video' ? 'image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime' : 'image/jpeg,image/png,image/webp'" class="visually-hidden" @change="upload"></label>
                  <button v-if="f.cover_url" type="button" class="adm-btn danger sm" @click="f.cover_url = ''">Убрать</button>
                  <span class="adm-note">{{ it.id ? 'Пример создаётся вашей моделью по промпту этого фотосета, бесплатно для администратора. Обычно это до минуты. Сначала сохраните промпт, если меняли его.' : 'Сохраните фотосет, и здесь появится кнопка генерации примера.' }}</span>
                </div>
              </div>
              <input v-model.trim="f.cover_url" class="adm-in" placeholder="Или ссылка https://…" maxlength="255" aria-label="Ссылка на картинку">
            </div>
            <label class="adm-check"><input v-model="f.is_active" type="checkbox">Показывать в приложении</label>
            <label class="adm-check"><input v-model="f.is_trending" type="checkbox">В блоке «Тренды»</label>
            <label v-if="f.is_trending" class="adm-f">Подпись в трендах<input v-model.trim="f.trend_hint" maxlength="64" placeholder="Из вашего селфи"></label>
          </div>
          <div v-else class="adm-grid">
            <label class="adm-f">Заголовок<input v-model.trim="f.title" required maxlength="120"></label>
            <label class="adm-f">Подзаголовок<input v-model.trim="f.subtitle" maxlength="160"></label>
            <label class="adm-f">Стикер<select v-model="f.sticker"><option :value="null">Без стикера</option><option v-for="s in stickers" :key="s" :value="s">{{ s }}</option></select></label>
            <label class="adm-f">Оформление<select v-model="f.style"><option value="ember">Огненная</option><option value="ink">Тёмная</option><option value="gold">Золотая</option></select></label>
            <label class="adm-f">Куда ведёт<select v-model="f.action"><option :value="null">Никуда</option><option value="topup">Пополнение</option><option value="plans">Подписки</option><option value="invite">Пригласить друга</option><option value="models">Выбор модели</option><option value="video">Видео</option></select></label>
            <label class="adm-f">Порядок<input v-model.number="f.sort" type="number" min="0"></label>
            <label class="adm-f">Скрыть после<input v-model="f.ends_at" type="datetime-local"></label>
            <label class="adm-check"><input v-model="f.is_active" type="checkbox">Показывать в приложении</label>
          </div>
          <div class="adm-actions">
            <button type="submit" class="adm-btn" :disabled="busy">Сохранить</button>
            <button type="button" class="adm-btn soft" @click="cancel">Отмена</button>
            <button v-if="it.id" type="button" class="adm-btn danger" :disabled="busy" @click="remove(it)">{{ confirming ? 'Точно удалить?' : 'Удалить' }}</button>
          </div>
        </form>
      </div>
      <div v-if="!items[tab].length" class="empty pad"><h3>Пока пусто</h3></div>
    </div>
  </section>
</template>

<script>
import { adminApi, api, ApiError } from '../services/api'
import { section, errorText } from './shared'

const local = (iso) => { if (!iso) return ''; const d = new Date(iso); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16) }

export default {
  name: 'AdminShowcase',
  mixins: [section],
  data: () => ({
    tabs: { photosets: 'Фото- и видеосеты', news: 'Новости' }, tab: 'photosets', items: { photosets: [], news: [] }, edit: null, f: {}, confirming: false, gen: { id: null }, bulk: { on: false, done: 0, total: 0, stop: false },
    stickers: ['art', 'coin', 'crown', 'cry', 'fire', 'gem', 'gift', 'heart', 'hourglass', 'hundred', 'magic', 'money', 'moneybag', 'party', 'robot', 'rocket', 'sleep', 'sparkles', 'star', 'starstruck', 'thinking', 'wave'],
  }),
  computed: { missing() { return this.items.photosets.filter((p) => p.id && p.is_active && p.kind !== 'video' && !p.cover_url) } },
  mounted() { this.load() },
  beforeUnmount() { this.bulk.stop = true; this.gone = true },
  methods: {
    videoExample(item) { return item.kind === 'video' && /\.(mp4|webm|mov)(?:$|[?#])/i.test(item.cover_url || '') },
    load() {
      return this.fetch(async () => {
        const [p, n] = await Promise.all([adminApi.list('photosets'), adminApi.list('news')])
        this.items = { photosets: p.data, news: n.data }
      })
    },
    toggle(it) {
      if (this.edit === it) return this.cancel()
      this.cancel()
      this.edit = it
      this.confirming = false
      this.f = { ...it, ends_at: local(it.ends_at) }
    },
    add() {
      this.cancel()
      const list = this.items[this.tab]
      const fresh = this.tab === 'photosets'
        ? { id: null, kind: 'image', name: '', description: '', prompt: '', cover_url: '', seed: Math.floor(Math.random() * 100), palette: 0, is_trending: false, trend_hint: '', sort: list.length, is_active: true }
        : { id: null, title: '', subtitle: '', sticker: 'rocket', style: 'ember', action: null, sort: list.length, is_active: true, ends_at: null }
      list.push(fresh)
      this.toggle(list[list.length - 1])
    },
    cancel() {
      this.items[this.tab] = this.items[this.tab].filter((x) => x.id)
      this.edit = null
    },
    upload(e) {
      const file = e.target.files[0]
      e.target.value = ''
      if (file) return this.run(async () => { this.f.cover_url = (await adminApi.upload(file, this.f.kind)).url }, 'Обложка загружена')
    },
    generateFrom(it, e) {
      const file = e.target.files[0]
      e.target.value = ''
      if (file) return this.generate(it, file)
    },
    /** Runs the photoset prompt on the server, waits for the picture and makes it the cover. */
    async makeExample(it, file) {
      this.gen = { id: it.id }
      try {
        let g = (await adminApi.example(it.id, file)).data
        for (let i = 0; i < 150 && !this.gone && ['queued', 'processing'].includes(g.status); i++) {
          await new Promise((r) => setTimeout(r, 2000))
          g = (await api.generation(g.id)).data
        }
        if (g.status !== 'done') throw new ApiError(422, { message: g.error === 'content_filtered' ? 'Модель отклонила запрос, попробуйте ещё раз или измените промпт' : g.error === 'unavailable' ? 'Модель сейчас недоступна, проверьте провайдера' : 'Не получилось создать пример, попробуйте ещё раз' })
        const saved = (await adminApi.useExample(it.id, g.id)).data
        it.cover_url = saved.cover_url
        if (this.edit === it) this.f.cover_url = saved.cover_url
        return true
      } finally {
        this.gen = { id: null }
      }
    },
    async generate(it, file = null) {
      try { await this.makeExample(it, file); this.app.toast('Пример готов и поставлен на обложку'); this.app.reloadCatalog() } catch (e) { this.app.toast(errorText(e), 'cry') }
    },
    async generateAll() {
      const list = [...this.missing]
      this.bulk = { on: true, done: 0, total: list.length, stop: false }
      let failed = 0
      for (const it of list) {
        if (this.bulk.stop) break
        try { await this.makeExample(it, null) } catch { failed++ }
        this.bulk.done++
      }
      this.bulk.on = false
      this.app.reloadCatalog()
      this.app.toast(failed ? `Готово, не получилось: ${failed}. Можно повторить.` : 'Примеры готовы', failed ? 'cry' : null)
    },
    async save() {
      const { id, ...body } = this.f
      if (this.tab === 'photosets') { body.cover_url = body.cover_url || null; body.trend_hint = body.is_trending ? body.trend_hint || null : null }
      else body.ends_at = body.ends_at ? new Date(body.ends_at).toISOString() : null
      await this.run(async () => {
        const r = id ? await adminApi.update(this.tab, id, body) : await adminApi.create(this.tab, body)
        this.items[this.tab] = this.items[this.tab].filter((x) => x.id && x.id !== r.data.id).concat(r.data).sort((a, b) => a.sort - b.sort || a.id - b.id)
        this.edit = null
        this.app.reloadCatalog()
      }, 'Сохранено')
    },
    async remove(it) {
      if (!this.confirming) { this.confirming = true; return }
      await this.run(async () => { await adminApi.remove(this.tab, it.id); this.items[this.tab] = this.items[this.tab].filter((x) => x !== it); this.edit = null; this.app.reloadCatalog() }, 'Удалено')
    },
  },
}
</script>
