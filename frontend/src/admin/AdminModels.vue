<template>
  <section class="adm-sec">
    <div class="adm-bar">
      <div class="filters grow" v-drag-scroll>
        <button v-for="(n, k) in kinds" :key="k" type="button" class="chip" :aria-pressed="String(kind === k)" @click="cancel(); kind = k">{{ n }}</button>
      </div>
      <button type="button" class="adm-btn soft sm" :disabled="syncing" @click="sync">{{ syncing ? 'Синхронизируем…' : 'Синхронизировать с ботом' }}</button>
      <button type="button" class="adm-btn sm" @click="add">Добавить модель</button>
    </div>
    <p class="adm-note">{{ notes[kind] }} Модели с плашкой «бот» связаны с таблицей models бота: название, цена и тариф читаются оттуда и сохраняются туда же. Новые модели бота подтягиваются сами каждые 5 минут.</p>
    <p v-if="synced" class="adm-note" aria-live="polite">Синхронизировано: связано {{ synced.linked }} из {{ synced.bot_total }} моделей бота, новых {{ synced.models }}, обновлено {{ synced.updated }}<template v-if="synced.missing">, нет в боте: {{ synced.missing }}</template>. Новых сетов из промптов бота: {{ synced.photosets }}.</p>
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <div v-else>
      <div v-for="m in list" :key="m.id || 'new'" :class="['adm-item', 'glass', m.is_active ? '' : 'off']">
        <button type="button" class="row" :aria-expanded="String(edit === m)" @click="toggle(m)">
          <span class="sq"><svg class="ic-s" aria-hidden="true"><use :href="'#i-' + icons[m.kind]" /></svg></span>
          <span class="t"><b>{{ m.name || 'Новая модель' }}<span v-if="m.badge" class="badge">{{ m.badge }}</span></b><span>{{ m.slug }}{{ m.provider_model ? ' · ' + m.provider_model : '' }}</span></span>
          <span class="end"><span v-if="m.bot_missing" class="adm-pill bad" title="Строка в таблице models бота удалена">нет в боте</span><span v-else-if="m.bot_model_id" class="adm-pill ok" :title="'Таблица models бота, id ' + m.bot_model_id">бот #{{ m.bot_model_id }}</span><span v-if="!m.is_active" class="adm-pill bad">выкл</span><span v-if="m.min_plan !== 'free'" class="adm-pill hot">{{ plans[m.min_plan] }}</span>{{ m.price }} ток.</span>
        </button>
        <form v-if="edit === m" class="adm-edit" @submit.prevent="save">
          <div class="adm-grid">
            <label v-if="!m.id" class="adm-f">Id (латиницей)<input v-model.trim="f.slug" required pattern="[a-z0-9_\-]+" maxlength="32" placeholder="my-model"></label>
            <label class="adm-f">Название<input v-model.trim="f.name" required maxlength="64"></label>
            <label class="adm-f">Цена, токенов<input v-model.number="f.price" type="number" min="0" required></label>
            <label class="adm-f">Доступна с тарифа<select v-model="f.min_plan"><option v-for="(n, k) in plans" :key="k" :value="k">{{ n }}</option></select></label>
            <label class="adm-f">Плашка<input v-model.trim="f.badge" maxlength="32" placeholder="Советуем"></label>
            <label class="adm-f">Порядок<input v-model.number="f.sort" type="number" min="0"></label>
            <label class="adm-f full">Подсказка под названием<input v-model.trim="f.hint" maxlength="255"></label>
            <label class="adm-check"><input v-model="f.is_active" type="checkbox">Показывать в приложении</label>
            <label class="adm-check"><input v-model="f.is_fast" type="checkbox">Значок «быстрая»</label>
            <label v-if="!m.id && m.kind !== 'song'" class="adm-check"><input v-model="f.create_in_bot" type="checkbox">Добавить и в бота (таблица models)</label>
          </div>
          <p v-if="!m.id && f.create_in_bot" class="adm-note">Бот получит новую строку: name = модель у провайдера, text_name = название, цена и тариф. Убедитесь, что бот умеет работать с этой моделью.</p>
          <div class="adm-sub">
            <span class="ctl-label">Провайдер</span>
            <div class="adm-grid">
              <label class="adm-f">Через что работает<select v-model="f.driver"><option v-for="d in drivers" :key="d" :value="d">{{ driverNames[d] || d }}</option></select></label>
              <label class="adm-f">Модель у провайдера<input v-model.trim="f.provider_model" maxlength="255" placeholder="gpt-image-1"></label>
            </div>
          </div>
          <div v-if="m.kind === 'video'" class="adm-sub">
            <span class="ctl-label">Длительность и цена</span>
            <div v-for="(d, i) in f.durations" :key="i" class="adm-line">
              <label class="adm-f">Секунд<input v-model.number="d.sec" type="number" min="1" max="600" required></label>
              <label class="adm-f">Множитель цены<input v-model.number="d.mult" type="number" min="0.1" step="0.1" required></label>
              <span class="adm-note" style="align-self:center">= {{ Math.ceil((f.price || 0) * (d.mult || 0)) }} ток.</span>
              <button type="button" class="x" aria-label="Убрать длительность" @click="f.durations.splice(i, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
            </div>
            <button type="button" class="adm-btn soft sm" style="justify-self:start" @click="f.durations.push({ sec: 5, mult: 1 })">Добавить длительность</button>
            <span class="ctl-label">Форматы кадра</span>
            <div class="adm-bar"><label v-for="r in ratios" :key="r" class="adm-check"><input v-model="f.formats" type="checkbox" :value="r">{{ r }}</label></div>
          </div>
          <div class="adm-sub">
            <span class="ctl-label">Параметры запроса к провайдеру</span>
            <div v-for="(o, i) in f.extra" :key="i" class="adm-line">
              <label class="adm-f">Параметр<input v-model.trim="o.key" pattern="[a-z_]+" maxlength="32" placeholder="resolution"></label>
              <label class="adm-f">Значение<input v-model.trim="o.value" maxlength="120" placeholder="720p"></label>
              <button type="button" class="x" aria-label="Убрать параметр" @click="f.extra.splice(i, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
            </div>
            <button type="button" class="adm-btn soft sm" style="justify-self:start" @click="f.extra.push({ key: '', value: '' })">Добавить параметр</button>
          </div>
          <div class="adm-actions">
            <button type="submit" class="adm-btn" :disabled="busy">Сохранить</button>
            <button type="button" class="adm-btn soft" @click="cancel">Отмена</button>
            <button v-if="m.id" type="button" class="adm-btn danger" :disabled="busy" @click="remove(m)">{{ confirming ? 'Точно удалить?' : 'Удалить' }}</button>
          </div>
        </form>
      </div>
      <div v-if="!list.length" class="empty pad"><h3>Моделей пока нет</h3></div>
    </div>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section, errorText, PLANS, DRIVERS, toPairs, fromPairs } from './shared'

export default {
  name: 'AdminModels',
  mixins: [section],
  data: () => ({
    kinds: { image: 'Картинки', video: 'Видео', text: 'Текст', song: 'Песни' },
    icons: { image: 'image', video: 'video', text: 'text', song: 'music' },
    notes: {
      image: 'Первая модель в списке работает как «Авто». Цена списывается за каждую картинку.',
      video: 'Цена модели умножается на множитель выбранной длительности.',
      text: 'Модели чата в боте. Цена списывается за ответ.',
      song: 'Цена за 30 секунд. Вкладка «Песня» включается в разделе «Настройки».',
    },
    plans: PLANS, driverNames: DRIVERS, drivers: [], ratios: ['16:9', '9:16', '1:1'],
    kind: 'image', models: [], edit: null, f: {}, confirming: false, syncing: false, synced: null,
  }),
  computed: { list() { return this.models.filter((m) => m.kind === this.kind) } },
  mounted() { this.load() },
  methods: {
    load() { return this.fetch(async () => { const r = await adminApi.list('models'); this.models = r.data; this.drivers = r.drivers }) },
    toggle(m) {
      if (this.edit === m) return this.cancel()
      this.cancel()
      this.open(m)
    },
    open(m) {
      this.edit = m
      this.confirming = false
      this.f = {
        slug: m.slug, name: m.name, hint: m.hint || '', price: m.price, badge: m.badge || '', min_plan: m.min_plan, sort: m.sort,
        is_active: m.is_active, is_fast: m.is_fast, driver: m.driver, provider_model: m.provider_model || '',
        durations: Object.entries(m.options.durations || {}).map(([sec, mult]) => ({ sec: Number(sec), mult })),
        formats: [...(m.options.formats || [])],
        extra: toPairs(m.options, ['durations', 'formats']),
        create_in_bot: false,
      }
    },
    add() {
      this.cancel()
      const fresh = { id: null, kind: this.kind, slug: '', name: '', hint: '', price: 10, badge: '', min_plan: 'free', sort: this.list.length, is_active: true, is_fast: false, driver: this.list[0]?.driver || this.drivers[0], provider_model: this.list[0]?.provider_model || '', options: this.kind === 'video' ? { durations: { 5: 1 }, formats: ['16:9', '9:16'] } : {} }
      this.models.push(fresh)
      // Opened directly: toggle() calls cancel(), which removed this unsaved row, so the button seemed to do nothing.
      this.open(this.models[this.models.length - 1])
      this.$nextTick(() => this.$el.querySelector('.adm-edit input')?.focus())
    },
    cancel() {
      this.models = this.models.filter((m) => m.id)
      this.edit = null
    },
    async save() {
      const m = this.edit, f = this.f
      const options = fromPairs(f.extra)
      if (m.kind === 'video') {
        if (!f.durations.length) return this.app.toast('Добавьте хотя бы одну длительность')
        options.durations = Object.fromEntries(f.durations.map((d) => [d.sec, d.mult]))
        options.formats = f.formats
      }
      const body = { name: f.name, hint: f.hint || null, price: f.price, badge: f.badge || null, min_plan: f.min_plan, sort: f.sort || 0, is_active: f.is_active, is_fast: f.is_fast, driver: f.driver, provider_model: f.provider_model || null, options }
      await this.run(async () => {
        const r = m.id ? await adminApi.update('models', m.id, body) : await adminApi.create('models', { ...body, kind: m.kind, slug: f.slug, create_in_bot: f.create_in_bot })
        this.models = this.models.filter((x) => x.id && x.id !== r.data.id).concat(r.data).sort((a, b) => a.sort - b.sort || a.id - b.id)
        this.edit = null
        this.app.reloadCatalog()
      }, 'Модель сохранена')
    },
    async sync() {
      this.syncing = true
      try {
        this.synced = (await adminApi.syncBot()).data
        await this.load()
        this.app.reloadCatalog()
      } catch (e) {
        this.app.toast(errorText(e), 'cry')
      } finally {
        this.syncing = false
      }
    },
    async remove(m) {
      if (!this.confirming) { this.confirming = true; return }
      await this.run(async () => { await adminApi.remove('models', m.id); this.models = this.models.filter((x) => x !== m); this.edit = null; this.app.reloadCatalog() }, 'Модель удалена')
    },
  },
}
</script>
